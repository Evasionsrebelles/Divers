<?php
// ============================================================
// Scraper d'événements — version "batch" pour GitHub Actions
//
// Différence clé avec la version "serveur web" :
// - Pas de header() HTTP (il n'y a pas de requête HTTP entrante,
//   ce script est lancé en ligne de commande par le workflow).
// - Le résultat est écrit dans un fichier events.json au lieu
//   d'être renvoyé en direct à un visiteur.
// - Ce fichier est ensuite committé dans le dépôt par le workflow,
//   et GitHub Pages le sert comme un fichier statique normal.
// ============================================================

$url = "https://tokyocheapo.com/events/";
$outputFile = __DIR__ . '/events.json';

/**
 * Écrit le résultat final dans events.json (jamais d'erreur fatale
 * qui ferait planter le job GitHub Actions).
 */
function writeResult(string $outputFile, array $events, ?string $warning = null): void {
    $payload = [
        "events"       => $events,
        "count"        => count($events),
        "generated_at" => gmdate('c'), // horodatage UTC ISO 8601, utile pour savoir si les données sont à jour
    ];
    if ($warning !== null) {
        $payload["warning"] = $warning;
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($outputFile, $json);

    // Message dans les logs du workflow (visible dans l'onglet Actions de GitHub)
    echo "[scrape-events] Terminé : " . count($events) . " événement(s) écrit(s) dans $outputFile\n";
    if ($warning !== null) {
        echo "[scrape-events] Avertissement : $warning\n";
    }
}

function fetchHtml(string $url): array {
    if (!function_exists('curl_init')) {
        return ["success" => false, "html" => null, "error" => "cURL non disponible."];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36",
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($html === false || $curlError !== '') {
        return ["success" => false, "html" => null, "error" => "Erreur réseau : " . $curlError];
    }
    if ($httpCode >= 400) {
        return ["success" => false, "html" => null, "error" => "Code HTTP $httpCode reçu du site cible."];
    }
    if (trim($html) === '') {
        return ["success" => false, "html" => null, "error" => "Réponse vide du site cible."];
    }

    return ["success" => true, "html" => $html, "error" => null];
}

function queryFirstMatch(DOMXPath $xpath, array $expressions, ?DOMNode $context = null): ?DOMNodeList {
    foreach ($expressions as $expr) {
        try {
            $result = $context ? $xpath->query($expr, $context) : $xpath->query($expr);
            if ($result !== false && $result->length > 0) {
                return $result;
            }
        } catch (\Throwable $e) {
            continue;
        }
    }
    return null;
}

function textOrDefault(?DOMNodeList $nodes, string $default = ''): string {
    if ($nodes === null || $nodes->length === 0) {
        return $default;
    }
    return trim($nodes->item(0)->textContent);
}

// ------------------------------------------------------------
// 1. Récupération du HTML
// ------------------------------------------------------------
$fetch = fetchHtml($url);
if (!$fetch['success']) {
    // Si ça échoue, on garde l'ancien events.json plutôt que de l'écraser
    // par un fichier vide — mieux vaut des données un peu anciennes que rien.
    echo "[scrape-events] Échec : " . $fetch['error'] . "\n";
    echo "[scrape-events] events.json existant conservé (non écrasé).\n";
    exit(0); // On sort proprement : le workflow ne committera rien de neuf
}
$html = $fetch['html'];

// ------------------------------------------------------------
// 2. Parsing HTML tolérant aux erreurs
// ------------------------------------------------------------
$doc = new DOMDocument();
$previousSetting = libxml_use_internal_errors(true);
$loaded = @$doc->loadHTML(
    mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
    LIBXML_NOWARNING | LIBXML_NOERROR
);
libxml_clear_errors();
libxml_use_internal_errors($previousSetting);

if (!$loaded) {
    echo "[scrape-events] Échec du parsing HTML. events.json existant conservé.\n";
    exit(0);
}

$xpath = new DOMXPath($doc);

// ------------------------------------------------------------
// 3. Sélection des blocs d'événements, avec repli
// ------------------------------------------------------------
$articleExpressions = [
    "//article[contains(@class, 'event')]",
    "//li[contains(@class, 'event')]",
    "//div[contains(@class, 'event-card')]",
    "//div[contains(@class, 'event')]",
    "//article",
];

$articles = queryFirstMatch($xpath, $articleExpressions);

if ($articles === null || $articles->length === 0) {
    echo "[scrape-events] Aucun bloc d'événement trouvé (structure du site probablement modifiée).\n";
    echo "[scrape-events] events.json existant conservé.\n";
    exit(0);
}

// ------------------------------------------------------------
// 4. Extraction des champs
// ------------------------------------------------------------
$titleExpressions = [".//h3", ".//h2", ".//*[contains(@class,'title')]"];
$descExpressions  = [".//p", ".//*[contains(@class,'desc')]", ".//*[contains(@class,'excerpt')]"];
$dateExpressions  = [".//time", ".//*[contains(@class,'date')]", ".//*[contains(@class,'when')]"];
$linkExpressions  = [".//a[@href]"];

$events = [];
$skipped = 0;

foreach ($articles as $article) {
    try {
        $titleNodes = queryFirstMatch($xpath, $titleExpressions, $article);
        $title = textOrDefault($titleNodes);

        if ($title === '') {
            $skipped++;
            continue;
        }

        $descNodes = queryFirstMatch($xpath, $descExpressions, $article);
        $dateNodes = queryFirstMatch($xpath, $dateExpressions, $article);
        $linkNodes = queryFirstMatch($xpath, $linkExpressions, $article);

        $link = '';
        if ($linkNodes !== null && $linkNodes->length > 0) {
            $href = $linkNodes->item(0)->getAttribute('href');
            if ($href !== '') {
                $link = str_starts_with($href, 'http') ? $href : rtrim($url, '/') . '/' . ltrim($href, '/');
            }
        }

        $textMeta = preg_replace('/\s+/', ' ', $article->textContent ?? '');

        $events[] = [
            "title"       => $title,
            "description" => textOrDefault($descNodes, "Aucune description disponible."),
            "date"        => textOrDefault($dateNodes, ""),
            "link"        => $link ?: $url,
            "raw_text"    => trim($textMeta ?? ''),
        ];
    } catch (\Throwable $e) {
        echo "[scrape-events] Erreur sur un bloc : " . $e->getMessage() . "\n";
        $skipped++;
        continue;
    }
}

if (empty($events)) {
    echo "[scrape-events] Blocs détectés mais aucune donnée exploitable. events.json existant conservé.\n";
    exit(0);
}

writeResult($outputFile, $events, $skipped > 0 ? "$skipped bloc(s) ignoré(s) car incomplets." : null);
