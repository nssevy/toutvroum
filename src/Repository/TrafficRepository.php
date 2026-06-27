<?php

namespace App\Repository;

class TrafficRepository
{
    private string $apiKey;
    private string $bbox = '1.8,48.2,2.8,49.1';

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
        'PERIPH' => ['Boulevard Périphérique', 'Périphérique'],
    ];

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
            'fields' => '{incidents{type,properties{id,iconCategory,magnitudeOfDelay,events{description,code},startTime,endTime,from,to,roadNumbers}}}',
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

    // "A3 - A186 (D36BIS)" => ['A3', 'A186', 'D36BIS']
    private function decouperEnMots(string $texte): array
    {
        $separateurs = ['-', '(', ')', ',', '/', '.'];
        $normalise = str_replace($separateurs, ' ', $texte);
        $tokens = explode(' ', $normalise);

        return array_values(array_filter(array_map('trim', $tokens), fn($t) => $t !== ''));
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

            $roads = $incident['properties']['roadNumbers'] ?? [];
            $from = $incident['properties']['from'] ?? '';
            $to = $incident['properties']['to'] ?? '';

            // Tous les "mots" du lieu : roadNumbers + from + to, en mots entiers
            $tokens = array_merge(
                $roads,
                $this->decouperEnMots($from),
                $this->decouperEnMots($to)
            );

            foreach ($mots as $mot) {
                // Code autoroute (A1, A86...) : doit apparaître comme mot entier.
                // Nom (Périphérique...) : recherche texte simple.
                $estCode = $mot[0] === 'A' && ctype_digit(substr($mot, 1));

                $match = $estCode
                    ? in_array($mot, $tokens, true)
                    : (str_contains($from, $mot) || str_contains($to, $mot));

                if ($match) {

                    $fermetures[] = [
                        'description' => $incident['properties']['events'][0]['description'] ?? 'Incident',
                        'from' => $from,
                        'to' => $to,
                        'debut' => $incident['properties']['startTime'] ?? null,
                        'fin' => $incident['properties']['endTime'] ?? null,
                        'gravite' => $gravite,
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