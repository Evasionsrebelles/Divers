<?php
// ============================================================
// Scraper d'événements — version robuste
// Objectif : ne jamais provoquer d'erreur fatale côté serveur,
// même si le site cible change de structure, est indisponible,
// ou renvoie un contenu inattendu.
// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// On désactive l'affichage des erreurs PHP dans la sortie
// (elles casseraient le JSON) mais on les logge quand même.
ini_set('display_errors', '0');
error_reporting(E_ALL);

$url = "https://tokyocheapo.com/events/";

/**
 * Renvoie une réponse JSON standardisée et arrête le script.
 * Toujours un tableau "events" (vide au pire), jamais d'erreur brute.
 */
function respond(array $events, ?string $warning = null): void {
    $payload = ["events" => $events, "count" => count($events)];
    if ($warning !== null) {
        $payload["warning"] = $warning;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Récupère le HTML via cURL (plus fiable que file_get_contents) :
 * timeout, gestion d'erreurs réseau, code HTTP.
 */
function fetchHtml(string $url): array {
    // "success" => bool, "html" => string|null, "error" => string|null
    if (!function_exists('curl_init')) {
        return ["success" => false, "html" => null, "error" => "cURL non disponible sur ce serveur."];
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
        return ["success" => false, "html" => null, "error" => "Le site cible a répondu avec le code HTTP $httpCode."];
    }
    if (trim($html) === '') {
        return ["success" => false, "html" => null, "error" => "Réponse vide du site cible."];
    }

    return ["success" => true, "html" => $html, "error" => null];
}

/**
 * Essaie une liste de requêtes XPath dans l'ordre et renvoie
 * le premier résultat non vide. Permet de survivre à un
 * changement de classes CSS sur le site cible.
 */
function queryFirstMatch(DOMXPath $xpath, array $expressions, ?DOMNode $context = null): ?DOMNodeList {
    foreach ($expressions as $expr) {
        try {
            $result = $context ? $xpath->query($expr, $context) : $xpath->query($expr);
            if ($result !== false && $result->length > 0) {
                return $result;
            }
        } catch (\Throwable $e) {
            // Expression invalide ou contexte incompatible : on continue.
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
    error_log("[recup-events] Échec de récupération : " . $fetch['error']);
    respond([], "Impossible de charger la page d'origine. Réessayez plus tard.");
}
$html = $fetch['html'];

// ------------------------------------------------------------
// 2. Parsing HTML tolérant aux erreurs
// ------------------------------------------------------------
$doc = new DOMDocument();
$previousSetting = libxml_use_internal_errors(true); // évite le flot de warnings HTML5 mal formé
$loaded = @$doc->loadHTML(
    mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),
    LIBXML_NOWARNING | LIBXML_NOERROR
);
libxml_clear_errors();
libxml_use_internal_errors($previousSetting);

if (!$loaded) {
    error_log("[recup-events] DOMDocument n'a pas pu parser le HTML.");
    respond([], "La page source n'a pas pu être analysée.");
}

$xpath = new DOMXPath($doc);

// ------------------------------------------------------------
// 3. Sélection des blocs d'événements, avec plusieurs
//    stratégies de repli si la structure a changé.
// ------------------------------------------------------------
$articleExpressions = [
    "//article[contains(@class, 'event')]",
    "//li[contains(@class, 'event')]",
    "//div[contains(@class, 'event-card')]",
    "//div[contains(@class, 'event')]",
    // Repli générique : tout article de la page (dernier recours)
    "//article",
];

$articles = queryFirstMatch($xpath, $articleExpressions);

if ($articles === null || $articles->length === 0) {
    error_log("[recup-events] Aucun bloc d'événement trouvé — structure du site probablement modifiée.");
    respond([], "Aucun événement n'a pu être identifié sur la page (structure du site peut-être modifiée).");
}

// ------------------------------------------------------------
// 4. Extraction des champs, chacun avec ses propres replis
// ------------------------------------------------------------
$titleExpressions = [".//h3", ".//h2", ".//*[contains(@class,'title')]"];
$descExpressions  = [".//p", ".//*[contains(@class,'desc')]", ".//*[contains(@class,'excerpt')]"];
$dateExpressions  = [
    ".//time",
    ".//*[contains(@class,'date')]",
    ".//*[contains(@class,'when')]",
];
$linkExpressions  = [".//a[@href]"];

$events = [];
$skipped = 0;

foreach ($articles as $article) {
    try {
        $titleNodes = queryFirstMatch($xpath, $titleExpressions, $article);
        $title = textOrDefault($titleNodes);

        // Un événement sans titre exploitable n'est pas fiable : on l'ignore
        // plutôt que de planter ou d'afficher une carte vide.
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
                // Gère les liens relatifs
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
        // Un événement malformé ne doit jamais faire planter tout le script.
        error_log("[recup-events] Erreur sur un bloc d'événement : " . $e->getMessage());
        $skipped++;
        continue;
    }
}

if (empty($events)) {
    respond([], "Des blocs ont été détectés mais aucune donnée exploitable n'a pu en être extraite.");
}

respond($events, $skipped > 0 ? "$skipped bloc(s) ignoré(s) car incomplets." : null);