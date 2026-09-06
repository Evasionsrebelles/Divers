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
$articles = $xpath->query("//div[contains(@class, 'cheapo-card')] | //div[contains(@id, 'event')] | //h3/ancestor::div[1]");


evenements.forEach(item => {
    const card = document.createElement('article');
    card.className = 'event-card';
    
    // Si votre script PHP extrait un lien spécifique, utilisez-le, sinon restez sur la page globale
    const lienEvenement = item.url ? item.url : 'https://tokyocheapo.com';
    
    card.innerHTML = `
        <h3 class="event-title">${item.title}</h3>
        <p class="event-details">${item.description}</p>
        <a href="${lienEvenement}" target="_blank" class="btn-link">Voir l'événement</a>
    `;
    
    grid.appendChild(card);
});

// Renvoie le tableau converti en format JSON exploitable
echo json_encode($events);
?>
