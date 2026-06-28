<?php

namespace App\Repository;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Source officielle DiRIF (DATEX II, open data Bison Futé).
 * Fermetures temps réel des autoroutes d'Île-de-France.
 * Le Périph n'y est PAS (géré par la Ville de Paris) -> voir TrafficRepository.
 */
class DatexRepository
{
    private string $url = 'https://transport.data.gouv.fr/resources/79174/download';
    private string $cacheFile;
    private int $cacheTtl = 600; // 10 min (le flux est mis à jour chaque heure)

    private array $autoroutes = ['A1', 'A3', 'A4', 'A6', 'A10', 'A13', 'A14', 'A86', 'A104'];

    public function __construct()
    {
        // Cache dans le projet (le temp système n'est pas writable par Apache/daemon).
        $dir = __DIR__ . '/../../var/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $this->cacheFile = $dir . '/datex.xml';
    }

    public function getAutoroutes(): array
    {
        return $this->autoroutes;
    }

    public function isValidAutoroute(string $autoroute): bool
    {
        return in_array($autoroute, $this->autoroutes, true);
    }

    public function getIncidents(string $autoroute): array
    {
        $xml = $this->chargerXml();
        if ($xml === null) {
            return ['error' => 'Impossible de récupérer les données'];
        }
        return $this->parser($xml, $autoroute);
    }

    // --- Chargement + cache disque (évite de retélécharger 3 Mo à chaque requête) ---
    private function chargerXml(): ?string
    {
        if (is_file($this->cacheFile) && (time() - filemtime($this->cacheFile)) < $this->cacheTtl) {
            $cache = file_get_contents($this->cacheFile);
            if ($cache !== false) {
                return $cache;
            }
        }

        $data = @file_get_contents($this->url);
        if ($data === false) {
            // Réseau KO : un cache périmé vaut mieux que rien.
            return is_file($this->cacheFile) ? (file_get_contents($this->cacheFile) ?: null) : null;
        }

        @file_put_contents($this->cacheFile, $data);
        return $data;
    }

    // --- Parsing DATEX II (public pour pouvoir tester hors-ligne) ---
    public function parser(string $xml, string $autoroute): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        if (!$dom->loadXML($xml)) {
            return ['error' => 'Données invalides'];
        }

        $xp = new DOMXPath($dom);
        // local-name() : on ignore les namespaces (soap, datex2, xsi)
        $records = $xp->query("//*[local-name()='situationRecord']");

        $fermetures = [];
        foreach ($records as $rec) {
            // 1. Source = DiRIF uniquement
            $src = $this->texte($xp, $rec, ".//*[local-name()='sourceIdentification']");
            if ($src === null || !str_contains($src, 'Île-de-France')) {
                continue;
            }

            // 2. Type = fermeture (pas un simple rétrécissement de voie)
            $type = $this->texte($xp, $rec, ".//*[local-name()='roadOrCarriagewayOrLaneManagementType']");
            if (!in_array($type, ['roadClosed', 'carriagewayClosed'], true)) {
                continue;
            }

            // 3. Route = l'autoroute demandée (A0006A -> A6)
            $route = $this->normaliserRoute($this->texte($xp, $rec, ".//*[local-name()='roadNumber']"));
            if ($route !== $autoroute) {
                continue;
            }

            // 4. Fermeture déjà terminée ? (le flux est curaté, on fait juste confiance à la validité)
            $fin = $this->texte($xp, $rec, ".//*[local-name()='overallEndTime']");
            if ($fin !== null && strtotime($fin) < time()) {
                continue;
            }

            $fermetures[] = [
                'description' => 'Route fermée',
                'from' => $this->ville($xp, $rec) ?? '',
                'to' => '',
                'debut' => $this->texte($xp, $rec, ".//*[local-name()='overallStartTime']"),
                'fin' => $fin,
                'gravite' => 4,
                'direction' => $this->directionTexte($xp, $rec),
            ];
        }

        return [
            'autoroute' => $autoroute,
            'statut' => count($fermetures) > 0 ? 'ferme' : 'libre',
            'incidents' => $fermetures,
            'total' => count($fermetures),
        ];
    }

    private function texte(DOMXPath $xp, DOMNode $ctx, string $query): ?string
    {
        $nodes = $xp->query($query, $ctx);
        if ($nodes && $nodes->length > 0) {
            $t = trim($nodes->item(0)->textContent);
            return $t === '' ? null : $t;
        }
        return null;
    }

    // "A0006A" -> "A6"  |  "A0086" -> "A86"  |  "A0104" -> "A104"
    private function normaliserRoute(?string $roadNumber): ?string
    {
        if ($roadNumber === null) {
            return null;
        }
        if (preg_match('/^([A-Z])0*(\d+)[A-Z]?$/', $roadNumber, $m)) {
            return $m[1] . $m[2];
        }
        return null;
    }

    // Commune (tpegOtherPointDescriptorType = townName)
    private function ville(DOMXPath $xp, DOMNode $rec): ?string
    {
        $q = ".//*[local-name()='name']"
            . "[.//*[local-name()='tpegOtherPointDescriptorType'][text()='townName']]"
            . "//*[local-name()='value']";
        return $this->texte($xp, $rec, $q);
    }

    // "De Wissous (A6) vers Paris - Porte d'Orléans" -> "Paris - Porte d'Orléans"
    private function directionTexte(DOMXPath $xp, DOMNode $rec): ?string
    {
        $vals = $xp->query(".//*[local-name()='generalPublicComment']//*[local-name()='value']", $rec);
        foreach ($vals as $v) {
            $t = trim($v->textContent);
            if (stripos($t, 'De ') === 0 && preg_match('/\svers\s+(.+)$/iu', $t, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }
}
