<?php
require_once 'vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$apiKey = $_ENV['TOMTOM_API_KEY'];

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Liste des autoroutes IDF
$autoroutes = [
    'A1' => ['A1'],
    'A3' => ['A3'],
    'A4' => ['A4'],
    'A6' => ['A6'],
    'A10' => ['A10'],
    'A13' => ['A13'],
    'A14' => ['A14'],
    'A86' => ['A86'],
    'A104' => ['A104'],
    'PERIPH' => ['Boulevard Périphérique', 'Périphérique'],
];

// Récupère l'autoroute demandée
$autoroute = strtoupper(trim($_GET['autoroute'] ?? ''));

if (!$autoroute || !isset($autoroutes[$autoroute])) {
    echo json_encode(['error' => 'Autoroute non reconnue']);
    exit;
}

// Appel API TomTom
$url = "https://api.tomtom.com/traffic/services/5/incidentDetails"
    . "?key={$apiKey}"
    . "&bbox=1.8,48.2,2.8,49.1"
    . "&fields={incidents{type,properties{id,iconCategory,magnitudeOfDelay,events{description,code},startTime,endTime,from,to,roadNumbers}}}"
    . "&language=fr-FR"
    . "&timeValidityFilter=present";

$response = file_get_contents($url);
$data = json_decode($response, true);

if (!$data || !isset($data['incidents'])) {
    echo json_encode(['error' => 'Impossible de récupérer les données']);
    exit;
}

// Filtre les incidents par autoroute
$motsRecherches = $autoroutes[$autoroute];
$incidentsFiltres = [];

foreach ($data['incidents'] as $incident) {
    $roads = $incident['properties']['roadNumbers'] ?? [];
    $from = $incident['properties']['from'] ?? '';
    $to = $incident['properties']['to'] ?? '';

    foreach ($motsRecherches as $mot) {
        if (
            in_array($mot, $roads) ||
            str_contains($from, $mot) ||
            str_contains($to, $mot)
        ) {

            $incidentsFiltres[] = [
                'description' => $incident['properties']['events'][0]['description'] ?? 'Incident',
                'from' => $from,
                'to' => $to,
                'debut' => $incident['properties']['startTime'] ?? null,
                'fin' => $incident['properties']['endTime'] ?? null,
                'gravite' => $incident['properties']['magnitudeOfDelay'] ?? 0,
            ];
            break;
        }
    }
}

// Statut global de l'autoroute
$statut = 'libre';
if (count($incidentsFiltres) > 0) {
    $maxGravite = max(array_column($incidentsFiltres, 'gravite'));
    if ($maxGravite >= 4) {
        $statut = 'ferme';
    } else {
        $statut = 'perturbe';
    }
}

echo json_encode([
    'autoroute' => $autoroute,
    'statut' => $statut,
    'incidents' => $incidentsFiltres,
    'total' => count($incidentsFiltres),
]);
?>