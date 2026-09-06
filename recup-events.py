<?php
// Autoriser votre propre site à lire ces données
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// URL cible à scraper
$url = "https://tokyocheapo.com/events/";

// Configuration pour simuler un navigateur internet normal
$options = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36\r\n"
    ]
];
$context = stream_context_create($options);
$html = @file_get_contents($url, false, $context);

if (!$html) {
    echo json_encode(["error" => "Impossible de charger la page d'origine."]);
    exit;
}

// Utilisation de DOMDocument pour analyser le code HTML
$doc = new DOMDocument();
@$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
$xpath = new DOMXPath($doc);

// On cible les blocs d'événements (basé sur la structure de la page)
// Note : Les classes CSS d'origine peuvent varier selon les mises à jour du site cible
$articles = $xpath->query("//article[contains(@class, 'event')] | //li[contains(@class, 'event')]");

$events = [];

foreach ($articles as $article) {
    // Extraction du titre
    $titleNode = $xpath->query(".//h3", $article)->item(0);
    
    if ($titleNode) {
        // Extraction de la description / résumé
        $descNode = $xpath->query(".//p", $article)->item(0);
        
        // Extraction des métadonnées (Dates, Prix) souvent situées dans du texte libre ou des balises span
        $textMeta = $article->textContent;
        
        // Nettoyage rapide pour l'affichage
        $events[] = [
            "title" => trim($titleNode->textContent),
            "description" => $descNode ? trim($descNode->textContent) : "Aucune description disponible.",
            "raw_text" => trim(preg_replace('/\s+/', ' ', $textMeta))
        ];
    }
}

// Renvoie le tableau converti en format JSON exploitable
echo json_encode($events);
?>
