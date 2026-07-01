<?php

namespace App\Repository;

use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * Source officielle DiRIF (DATEX II, open data Bison Futé).
 * Fermetures + perturbations temps réel des autoroutes d'Île-de-France.
 * Le Périph n'y est PAS (géré par la Ville de Paris) -> voir TrafficRepository.
 *
 * 3 états par autoroute :
 *   - ferme    : fermeture totale de la voie principale (gravité 4)
 *   - perturbe : voie(s) fermée(s), travaux, accident, obstacle, bretelle fermée (gravité 2)
 *   - libre    : rien d'actif
 */
class DatexRepository
{
    private string $url = 'https://transport.data.gouv.fr/resources/79174/download';
    private string $cacheFile;
    private int $cacheTtl = 600; // 10 min (le flux est mis à jour chaque heure)

    private array $autoroutes = ['A1', 'A3', 'A4', 'A6', 'A10', 'A13', 'A14', 'A86', 'A104'];

    // Terminus par autoroute et par sens (fallback quand DiRIF n'écrit pas "De X vers Y").
    // Radiales = cardinal ; rocades A86/A104 = intérieur/extérieur.
    private array $termini = [
        'A1'   => ['northBound' => 'Lille',   'southBound' => 'Paris'],
        'A3'   => ['northBound' => 'Roissy',  'southBound' => 'Paris'],
        'A4'   => ['eastBound' => 'Metz',     'westBound'  => 'Paris'],
        'A6'   => ['southBound' => 'Lyon',    'northBound' => 'Paris'],
        'A10'  => ['southBound' => 'Bordeaux', 'northBound' => 'Paris'],
        'A13'  => ['westBound' => 'Caen',     'eastBound'  => 'Paris'],
        'A14'  => ['westBound' => 'Orgeval',  'eastBound'  => 'La Défense'],
        'A86'  => ['innerRing' => 'intérieur', 'outerRing' => 'extérieur'],
        'A104' => ['northBound' => 'Roissy',  'southBound' => 'A5-A6', 'innerRing' => 'intérieur', 'outerRing' => 'extérieur'],
    ];

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

        // Un incident par baseId (l'id sans le suffixe -N). Une même opération DiRIF
        // est publiée en plusieurs sous-records, parfois sous des types différents
        // (chantier = MaintenanceWorks + RoadOrCarriagewayOrLaneManagement). On garde
        // le plus grave par baseId.
        $incidents = [];

        foreach ($records as $rec) {
            // 1. Source = DiRIF uniquement
            $src = $this->texte($xp, $rec, ".//*[local-name()='sourceIdentification']");
            if ($src === null || !str_contains($src, 'Île-de-France')) {
                continue;
            }

            // 2. Actif ? (pas un événement prévu/à-risque, déjà commencé, pas terminé)
            if (!$this->estActif($xp, $rec)) {
                continue;
            }

            // 2bis. Véhicule/incident hors voie de circulation (sur BAU) -> ne bloque rien.
            if ($this->horsCirculation($xp, $rec)) {
                continue;
            }

            // 3. Route = l'autoroute demandée (roadNumber "A0006A", roadName/linkName "A6")
            if (!$this->routeCorrespond($xp, $rec, $autoroute)) {
                continue;
            }

            // 4. Classement : gravité + cause. null = type ignoré.
            $classe = $this->classer($xp, $rec);
            if ($classe === null) {
                continue;
            }

            $incident = [
                'description' => $classe['cause'],
                'gravite'     => $classe['gravite'],
                'from'        => $this->ville($xp, $rec) ?? '',
                'to'          => '',
                'direction'   => $this->directionTexte($xp, $rec, $autoroute),
                'debut'       => $this->texte($xp, $rec, ".//*[local-name()='overallStartTime']"),
                'fin'         => $this->texte($xp, $rec, ".//*[local-name()='overallEndTime']"),
            ];

            $cle = preg_replace('/-\d+$/', '', $rec->getAttribute('id'));

            // Garde le plus grave ; à gravité égale, le début le plus ancien.
            if (!isset($incidents[$cle])
                || $incident['gravite'] > $incidents[$cle]['gravite']
                || ($incident['gravite'] === $incidents[$cle]['gravite']
                    && $this->avant($incident['debut'], $incidents[$cle]['debut']))
            ) {
                $incidents[$cle] = $incident;
            }
        }

        $incidents = array_values($incidents);

        // Statut = pire gravité présente.
        $gravMax = 0;
        foreach ($incidents as $i) {
            $gravMax = max($gravMax, $i['gravite']);
        }
        $statut = $gravMax >= 4 ? 'ferme' : ($gravMax > 0 ? 'perturbe' : 'libre');

        return [
            'autoroute' => $autoroute,
            'statut'    => $statut,
            'incidents' => $incidents,
            'total'     => count($incidents),
        ];
    }

    // --- Classement : rend ['gravite' => int, 'cause' => string] ou null si ignoré ---
    private function classer(DOMXPath $xp, DOMNode $rec): ?array
    {
        $xsi = $this->xsiType($rec);

        // Travaux / accidents / obstacles = perturbation (gravité 2).
        switch ($xsi) {
            case 'MaintenanceWorks':
            case 'ConstructionWorks':
                return ['gravite' => 2, 'cause' => 'Travaux'];
            case 'Accident':
                return ['gravite' => 2, 'cause' => 'Accident'];
            case 'VehicleObstruction':
                return ['gravite' => 2, 'cause' => 'Véhicule arrêté'];
            case 'GeneralObstruction':
                return ['gravite' => 2, 'cause' => 'Obstacle sur la voie'];
            case 'RoadOrCarriagewayOrLaneManagement':
                return $this->classerGestion($xp, $rec);
        }

        // Ignorés : SpeedManagement, ReroutingManagement, GeneralNetworkManagement,
        // GeneralInstructionOrMessageToRoadUsers, etc.
        return null;
    }

    // Gestion de voie/chaussée : fermeture totale, bretelle, ou voie(s).
    private function classerGestion(DOMXPath $xp, DOMNode $rec): ?array
    {
        $type = $this->texte($xp, $rec, ".//*[local-name()='roadOrCarriagewayOrLaneManagementType']");
        $fermeture = in_array($type, ['roadClosed', 'carriagewayClosed'], true);

        // Bretelle (entrée/sortie) : la voie principale roule -> perturbation, jamais fermée.
        if ($this->estBretelle($xp, $rec)) {
            return ['gravite' => 2, 'cause' => 'Bretelle fermée'];
        }

        if ($fermeture && $this->toutesVoiesBloquees($xp, $rec)) {
            return ['gravite' => 4, 'cause' => 'Route fermée'];
        }

        // Fermeture partielle / laneClosures / narrowLanes -> voie(s).
        $voies = $this->libelleVoies($xp, $rec);
        if ($type === 'narrowLanes') {
            return ['gravite' => 2, 'cause' => $voies ? "Voie rétrécie ($voies)" : 'Voie rétrécie'];
        }
        if ($fermeture || $type === 'laneClosures' || $voies !== '') {
            if ($voies === '') {
                return ['gravite' => 2, 'cause' => 'Voie fermée'];
            }
            // Accord : "Voies 1 et 2 fermées" / "Voie 1 fermée" / "BAU fermée"
            $accord = str_starts_with($voies, 'Voies') ? 'fermées' : 'fermée';
            return ['gravite' => 2, 'cause' => "$voies $accord"];
        }

        return null;
    }

    // Incident signalé "hors voie de circulation" (véhicule sur BAU) : ne bloque pas.
    private function horsCirculation(DOMXPath $xp, DOMNode $rec): bool
    {
        $vals = $xp->query(".//*[local-name()='generalPublicComment']//*[local-name()='value']", $rec);
        foreach ($vals as $v) {
            if (stripos($v->textContent, 'hors voie de circulation') !== false) {
                return true;
            }
        }
        return false;
    }

    // --- Actif = certain (pas riskOf), commencé, pas terminé ---
    private function estActif(DOMXPath $xp, DOMNode $rec): bool
    {
        $prob = $this->texte($xp, $rec, ".//*[local-name()='probabilityOfOccurrence']");
        if ($prob === 'riskOf') {
            return false;
        }
        $debut = $this->texte($xp, $rec, ".//*[local-name()='overallStartTime']");
        if ($debut !== null && strtotime($debut) > time()) {
            return false;
        }
        $fin = $this->texte($xp, $rec, ".//*[local-name()='overallEndTime']");
        if ($fin !== null && strtotime($fin) < time()) {
            return false;
        }
        return true;
    }

    // --- La route du record correspond-elle à l'autoroute demandée ? ---
    // DiRIF IDF ne remplit pas roadNumber : on cherche aussi roadName et linkName.
    private function routeCorrespond(DOMXPath $xp, DOMNode $rec, string $autoroute): bool
    {
        $candidats = [];

        $rn = $this->normaliserRoute($this->texte($xp, $rec, ".//*[local-name()='roadNumber']"));
        if ($rn !== null) {
            $candidats[] = $rn;
        }

        $noms = $xp->query(
            ".//*[local-name()='roadName']//*[local-name()='value']"
            . " | .//*[local-name()='name'][.//*[local-name()='tpegOtherPointDescriptorType'][text()='linkName']]//*[local-name()='value']",
            $rec
        );
        foreach ($noms as $n) {
            $norm = $this->normaliserRoute(trim($n->textContent));
            if ($norm !== null) {
                $candidats[] = $norm;
            }
        }

        return in_array($autoroute, $candidats, true);
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

    // xsi:type du record ("ns2:MaintenanceWorks" -> "MaintenanceWorks")
    private function xsiType(DOMNode $rec): string
    {
        $t = '';
        if ($rec->attributes !== null) {
            $attr = $rec->attributes->getNamedItemNS('http://www.w3.org/2001/XMLSchema-instance', 'type');
            $t = $attr ? $attr->nodeValue : '';
        }
        return preg_replace('/^[^:]+:/', '', $t);
    }

    // Bretelle = chaussée touchée uniquement entrée/sortie (pas la voie principale).
    private function estBretelle(DOMXPath $xp, DOMNode $rec): bool
    {
        $carr = $xp->query(".//*[local-name()='carriageway']", $rec);
        if (!$carr || $carr->length === 0) {
            return false;
        }
        $slip = false;
        foreach ($carr as $c) {
            $v = trim($c->textContent);
            if ($v === 'mainCarriageway') {
                return false; // voie principale touchée -> pas qu'une bretelle
            }
            if (in_array($v, ['entrySlipRoad', 'exitSlipRoad'], true)) {
                $slip = true;
            }
        }
        return $slip;
    }

    // Vraie fermeture de route = toutes les voies bloquées (ou aucune voie précise listée).
    private function toutesVoiesBloquees(DOMXPath $xp, DOMNode $rec): bool
    {
        $voies = $xp->query(".//*[local-name()='affectedCarriagewayAndLanes']//*[local-name()='lane']", $rec);
        $tokens = [];
        foreach ($voies as $v) {
            $tokens[] = trim($v->textContent);
        }

        // Aucune voie précise listée -> chaussée entière fermée.
        if (count($tokens) === 0) {
            return true;
        }
        // Marqueur explicite "toutes voies".
        if (in_array('allLanesCompleteCarriageway', $tokens, true)) {
            return true;
        }

        $total = $this->texte($xp, $rec, ".//*[local-name()='originalNumberOfLanes']");
        if ($total === null) {
            return true; // total inconnu : prudence, on considère fermée
        }

        // BAU (hardShoulder) n'est pas une voie de circulation : ne compte pas.
        $vraiesVoies = array_filter($tokens, fn($t) => $t !== 'hardShoulder');
        return count($vraiesVoies) >= (int) $total;
    }

    // "lane1,hardShoulder" -> "Voie 1 et BAU"  |  "lane1,lane2" -> "Voies 1 et 2"
    private function libelleVoies(DOMXPath $xp, DOMNode $rec): string
    {
        $voies = $xp->query(".//*[local-name()='affectedCarriagewayAndLanes']//*[local-name()='lane']", $rec);
        $labels = [];
        foreach ($voies as $v) {
            $t = trim($v->textContent);
            if ($t === 'allLanesCompleteCarriageway') {
                continue;
            }
            if ($t === 'hardShoulder') {
                $labels[] = 'BAU';
            } elseif (preg_match('/^lane(\d+)$/', $t, $m)) {
                $labels[] = 'voie ' . $m[1];
            }
        }
        $labels = array_values(array_unique($labels));

        if (count($labels) === 0) {
            return '';
        }
        // Majuscule sur le premier mot, accord pluriel.
        $prefixe = count($labels) > 1 ? 'Voies' : 'Voie';
        $liste = array_map(fn($l) => preg_replace('/^voie /', '', $l), $labels);
        // "Voies 1 et 2" / "Voies 1, 2 et 3" / "Voie 1" / "BAU"
        if ($labels[0] === 'BAU' && count($labels) === 1) {
            return 'BAU';
        }
        $dernier = array_pop($liste);
        return $prefixe . ' ' . (count($liste) ? implode(', ', $liste) . ' et ' . $dernier : $dernier);
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

    // "A0006A" -> "A6"  |  "A0086" -> "A86"  |  "A6" -> "A6"  |  "N118" -> "N118"
    private function normaliserRoute(?string $roadNumber): ?string
    {
        if ($roadNumber === null) {
            return null;
        }
        if (preg_match('/^([A-Z])0*(\d+)[A-Z]?$/', trim($roadNumber), $m)) {
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

    // Sens de circulation. Cascade pour toujours donner un sens exploitable :
    //  1. "De X vers Y" (texte DiRIF, le plus précis)  -> "X → Y"
    //  2. tpegDirection + terminus de l'autoroute       -> "vers Bordeaux"
    //  3. innerRing/outerRing (rocades)                 -> "sens intérieur/extérieur"
    //  4. bothWays                                      -> "les deux sens"
    private function directionTexte(DOMXPath $xp, DOMNode $rec, string $autoroute): ?string
    {
        // 1. Texte explicite "De X vers Y"
        $vals = $xp->query(".//*[local-name()='generalPublicComment']//*[local-name()='value']", $rec);
        foreach ($vals as $v) {
            $t = trim($v->textContent);
            if (stripos($t, 'De ') === 0 && preg_match('/^De\s+(.+?)\s+vers\s+(.+)$/iu', $t, $m)) {
                return $this->nettoyer($m[1]) . ' → ' . $this->nettoyer($m[2]);
            }
        }

        // 2-4. Fallback via tpegDirection
        $card = $this->texte($xp, $rec, ".//*[local-name()='tpegDirection']");
        if ($card === null) {
            return null;
        }
        if ($card === 'bothWays') {
            return 'les deux sens';
        }

        $dest = $this->termini[$autoroute][$card] ?? null;
        if ($dest !== null) {
            // "intérieur"/"extérieur" -> "sens intérieur" ; ville -> "vers Bordeaux"
            return in_array($dest, ['intérieur', 'extérieur'], true) ? "sens $dest" : "vers $dest";
        }

        // Dernier recours : cardinal générique.
        $cardinaux = ['northBound' => 'le nord', 'southBound' => 'le sud', 'eastBound' => "l'est", 'westBound' => "l'ouest"];
        return isset($cardinaux[$card]) ? 'vers ' . $cardinaux[$card] : null;
    }

    // Retire les annotations entre parenthèses : "Wissous (A6)" -> "Wissous"
    private function nettoyer(string $lieu): string
    {
        return trim(preg_replace('/\s*\([^)]*\)/', '', $lieu));
    }
}
