<?php

namespace App\Repository;

class TrafficRepository
{
    private string $apiKey;
    private string $bbox = '1.8,48.2,2.8,49.1';

    // On ignore les fermetures démarrées il y a plus de X jours (données périmées)
    private int $maxAgeJours = 7;

    private array $autoroutes = [
        'A1' => ['A1'],
        'A3' => ['A3'],
        'A4' => ['A4'],
        'A6' => ['A6'],
        'A10' => ['A10'],
        'A13' => ['A13'],
        'A14' => ['A14'],
        'A86' => ['A86'],
        'A104' => ['A104'],
        // Vrai Périph parisien : signalé "Périphérique Intérieur/Extérieur".
        // (le mot nu "Périphérique" attrape des voies locales type D84)
        'PERIPH' => ['Périphérique Intérieur', 'Périphérique Extérieur'],
    ];

    // Ville affichée sur les panneaux pour le sens "sortant" (loin de Paris).
    // Les radiales ont 2 sens : vers Paris / vers cette ville.
    private array $villesSortantes = [
        'A1' => 'Lille',
        'A3' => 'Lille',
        'A4' => 'Metz–Nancy',
        'A6' => 'Lyon',
        'A10' => 'Bordeaux–Nantes',
        'A13' => 'Rouen–Caen',
        'A14' => 'Rouen',
    ];

    // Ancre Paris (lng, lat) pour déterminer le sens.
    private array $paris = [2.3522, 48.8566];

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function getAutoroutes(): array
    {
        return array_keys($this->autoroutes);
    }

    public function isValidAutoroute(string $autoroute): bool
    {
        return isset($this->autoroutes[$autoroute]);
    }

    public function getIncidents(string $autoroute): array
    {
        $params = http_build_query([
            'key' => $this->apiKey,
            'bbox' => $this->bbox,
            'fields' => '{incidents{type,geometry{type,coordinates},properties{id,iconCategory,magnitudeOfDelay,events{description,code},startTime,endTime,from,to,roadNumbers}}}',
            'language' => 'fr-FR',
            'timeValidityFilter' => 'present',
        ]);

        $url = "https://api.tomtom.com/traffic/services/5/incidentDetails?{$params}";
        $response = file_get_contents($url);

        if (!$response) {
            return ['error' => 'Impossible de récupérer les données'];
        }

        $data = json_decode($response, true);

        if (!$data || !isset($data['incidents'])) {
            return ['error' => 'Données invalides'];
        }

        return $this->filtrerParAutoroute($data['incidents'], $autoroute);
    }

    // Fermeture démarrée dans les X derniers jours ?
    private function estRecent(?string $startTime): bool
    {
        if (!$startTime)
            return false; // pas de date = on écarte (donnée douteuse)

        $debut = strtotime($startTime);
        if ($debut === false)
            return false;

        $limite = time() - ($this->maxAgeJours * 86400);
        return $debut >= $limite;
    }

    // Déduit le sens de circulation à partir de la géométrie TomTom.
    // Les coords sont ordonnées dans le sens de circulation (1er -> dernier point).
    // Retourne "Paris", la ville sortante, ou null si indéterminable.
    private function direction(string $autoroute, array $geometry): ?string
    {
        // Anneaux (A86, A104, Périph) : intérieur/extérieur, pas géré ici.
        $villeSortante = $this->villesSortantes[$autoroute] ?? null;
        if (!$villeSortante)
            return null;

        $points = $this->pointsLigne($geometry);
        if (count($points) < 2)
            return null;

        $premier = $points[0];
        $dernier = $points[count($points) - 1];

        // Circulation va du premier vers le dernier point.
        // Si on se rapproche de Paris -> sens Paris, sinon -> sens sortant.
        return $this->distParis($dernier) < $this->distParis($premier)
            ? 'Paris'
            : $villeSortante;
    }

    // Extrait la liste de points [lng, lat] d'une geometry (LineString ou Point).
    private function pointsLigne(array $geometry): array
    {
        $coords = $geometry['coordinates'] ?? [];
        if (!$coords)
            return [];

        // LineString: [[lng,lat], ...]   Point: [lng,lat]
        return is_array($coords[0]) ? $coords : [$coords];
    }

    // Distance² approx vers Paris (longitude pondérée par la latitude).
    private function distParis(array $point): float
    {
        $dx = ($point[0] - $this->paris[0]) * cos(deg2rad($this->paris[1]));
        $dy = $point[1] - $this->paris[1];
        return $dx * $dx + $dy * $dy;
    }

    private function filtrerParAutoroute(array $incidents, string $autoroute): array
    {
        $mots = $this->autoroutes[$autoroute];
        $fermetures = [];

        foreach ($incidents as $incident) {
            $gravite = $incident['properties']['magnitudeOfDelay'] ?? 0;

            // On garde uniquement les fermetures
            if ($gravite < 4)
                continue;

            // On écarte les fermetures périmées (vieux startTime)
            $debut = $incident['properties']['startTime'] ?? null;
            if (!$this->estRecent($debut))
                continue;

            $roads = $incident['properties']['roadNumbers'] ?? [];
            $from = $incident['properties']['from'] ?? '';
            $to = $incident['properties']['to'] ?? '';

            foreach ($mots as $mot) {
                // Code autoroute (A1, A86...) : la route DOIT être dans roadNumbers.
                //   (sinon on attrape les D/N d'accès dont le from/to cite l'échangeur)
                // Nom (Périphérique...) : pas de code dans roadNumbers -> match texte.
                $estCode = $mot[0] === 'A' && ctype_digit(substr($mot, 1));

                $match = $estCode
                    ? in_array($mot, $roads, true)
                    : (str_contains($from, $mot) || str_contains($to, $mot));

                if ($match) {

                    $fermetures[] = [
                        'description' => $incident['properties']['events'][0]['description'] ?? 'Incident',
                        'from' => $from,
                        'to' => $to,
                        'debut' => $incident['properties']['startTime'] ?? null,
                        'fin' => $incident['properties']['endTime'] ?? null,
                        'gravite' => $gravite,
                        'direction' => $this->direction($autoroute, $incident['geometry'] ?? []),
                    ];
                    break;
                }
            }
        }

        $statut = count($fermetures) > 0 ? 'ferme' : 'libre';

        return [
            'autoroute' => $autoroute,
            'statut' => $statut,
            'incidents' => $fermetures,
            'total' => count($fermetures),
        ];
    }
}