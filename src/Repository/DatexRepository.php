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

            // 2bis. Vraie fermeture ? DiRIF tague parfois "roadClosed" alors qu'une
            // seule voie est touchée (lane1/3). Si des voies précises sont listées
            // et qu'elles sont moins nombreuses que le total -> fermeture de voie, pas de route.
            if (!$this->toutesVoiesBloquees($xp, $rec)) {
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

            $debut = $this->texte($xp, $rec, ".//*[local-name()='overallStartTime']");

            // 5. Dédoublonnage : une opération DiRIF est publiée en plusieurs
            // sous-records ("260628-001316-1", "-102"...). Même base = même fermeture.
            $cle = preg_replace('/-\d+$/', '', $rec->getAttribute('id'));

            $fermeture = [
                'description' => 'Route fermée',
                'from' => $this->ville($xp, $rec) ?? '',
                'to' => '',
                'debut' => $debut,
                'fin' => $fin,
                'gravite' => 4,
                'direction' => $this->directionTexte($xp, $rec),
            ];

            // On garde la version au début le plus ancien (fermé "depuis" correct).
            if (!isset($fermetures[$cle]) || $this->avant($debut, $fermetures[$cle]['debut'])) {
                $fermetures[$cle] = $fermeture;
            }
        }

        $fermetures = array_values($fermetures);

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

    // Vraie fermeture de route = toutes les voies bloquées (ou aucune voie précise listée).
    // Si N voies précises sont listées et N < nombre total -> simple fermeture de voie.
    private function toutesVoiesBloquees(DOMXPath $xp, DOMNode $rec): bool
    {
        $voies = $xp->query(".//*[local-name()='affectedCarriagewayAndLanes']//*[local-name()='lane']", $rec);
        $nbVoies = $voies ? $voies->length : 0;

        // Aucune voie précise listée -> on considère la chaussée fermée.
        if ($nbVoies === 0) {
            return true;
        }

        $total = $this->texte($xp, $rec, ".//*[local-name()='originalNumberOfLanes']");
        // Total inconnu : prudence, on garde (mieux vaut un faux positif rare qu'un manque).
        if ($total === null) {
            return true;
        }

        return $nbVoies >= (int) $total;
    }

    // $a est-il antérieur à $b ? (null = inconnu, traité comme le plus ancien)
    private function avant(?string $a, ?string $b): bool
    {
        if ($a === null) {
            return true;
        }
        if ($b === null) {
            return false;
        }
        return strtotime($a) < strtotime($b);
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

    // "De Wissous (A6) vers Paris - Porte d'Orléans" -> "Wissous → Paris - Porte d'Orléans"
    private function directionTexte(DOMXPath $xp, DOMNode $rec): ?string
    {
        $vals = $xp->query(".//*[local-name()='generalPublicComment']//*[local-name()='value']", $rec);
        foreach ($vals as $v) {
            $t = trim($v->textContent);
            if (stripos($t, 'De ') === 0 && preg_match('/^De\s+(.+?)\s+vers\s+(.+)$/iu', $t, $m)) {
                $origine = $this->nettoyer($m[1]);
                $destination = $this->nettoyer($m[2]);
                return "{$origine} → {$destination}";
            }
        }
        return null;
    }

    // Retire les annotations entre parenthèses : "Wissous (A6)" -> "Wissous"
    private function nettoyer(string $lieu): string
    {
        return trim(preg_replace('/\s*\([^)]*\)/', '', $lieu));
    }
}
