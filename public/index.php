<?php
require_once __DIR__ . '/../config/bootstrap.php';

use App\Repository\TrafficRepository;

$apiKey = $_ENV['TOMTOM_API_KEY'];
$repository = new TrafficRepository($apiKey);

// Si c'est un appel API (AJAX)
if (isset($_GET['autoroute'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');

    $autoroute = strtoupper(trim($_GET['autoroute']));

    if (!$repository->isValidAutoroute($autoroute)) {
        echo json_encode(['error' => 'Autoroute non reconnue']);
        exit;
    }

    echo json_encode($repository->getIncidents($autoroute));
    exit;
}

// Sinon on affiche la page ($twig vient de bootstrap.php)
echo $twig->render('home.html.twig', [
    'autoroutes' => $repository->getAutoroutes(),
]);