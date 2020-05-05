<?php
/*PhpDoc:
name: rpicom2.inc.php
title: rpicom2.inc.php - structuration des Rpicom en classes
doc: |
  buildInclusionGraph() en cours d'écriture
  defDataset() à adapter pour utiliser le graphe
journal: |
  5/5/2020:
    - $stats:
        geolocalisé: 40016
        majoré: 5802
        erreur: 1467
  2/5/2020:
    - création
includes:
classes:
functions:
*/
require_once __DIR__.'/node.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// correspond au contenu du Rpicom
class Rpicoms {
  protected $rpicoms; // [ id => Rpicom2 ]
  
  function __construct(array $rpicoms) {
    foreach ($rpicoms as $id => $rpicom)
      $this->rpicoms[$id] = new Rpicom2($id, $rpicom);
    foreach ($this->rpicoms as $id => $rpicom)
      $rpicom->finalize($this);
  }
  
  // renvoit si $name est un code INSEE alors le Rpicom sinon s'il est de la forme {id}@{dv} alors la version sinon null
  function __get(string $name) {
    if (isset($this->rpicoms[$name]))
      return $this->rpicoms[$name];
    if (false === $pos = strpos($name, '@'))
      return null;
    $id = substr($name, 0, $pos);
    $dv = substr($name, $pos+1);
    return $this->rpicoms[$id]->$dv;
  }
    
  function asArray(): array {
    $rpicoms = [];
    foreach ($this->rpicoms as $id => $rpicom) {
      $rpicoms[$id] = $rpicom->asArray();
    }
    return $rpicoms;
  }
  
  function dump(int $level=4, int $indentation=2): void {
    if (0) { // test __get()
      foreach ([
        '01015',
        '01015@2016-01-01',
        'xxx',
        '01015@201x',
      ] as $id)
        if ($this->$id)
          echo Yaml::dump([$id => $this->$id->asArray()], $level, $indentation);
        else
          echo "$id n'est pas une clé valide\n";
      die();
    }
    if (0) { // test __get() + __toString()
      foreach ([
        '01015',
        '01003',
        '01015@2016-01-01',
        'xxx',
        '01015@201x',
      ] as $id)
        echo Yaml::dump([$id => $this->$id ? $this->$id->__toString() : 'non valide'], $level, $indentation);
      die();
    }
    if (0) { // test EvtCreation::asArray()
      foreach (['08377','09342'] as $id) {
        //print_r($this->rpicoms[$id]);
        echo Yaml::dump([$id => $this->rpicoms[$id]->asArray()], $level, $indentation);
      }
      //die();
    }
    foreach ($this->rpicoms as $id => $rpicom) {
      //if ($id == '19034')
      echo Yaml::dump([$id => $rpicom->asArray()], $level, $indentation);
      //if ($id == '19034x')
      //print_r($rpicom);
    }
  }
  
  function buildInclusionGraph() {
    if (0) { // Question Y avait-il des COMS en 1943 ?
      $nbre = 0;
      foreach ($this->rpicoms as $rpicom) {
        $rpicom->coms1943();
        //if (++$nbre > 100) break;
      }
    }
    //foreach ($this->rpicoms as $rpicom)
      //$rpicom->setChildren($this);
    foreach ($this->rpicoms as $rpicom)
      $rpicom->buildInclusionGraph($this);
    
    if (0) { // stats
      echo count($this->rpicoms)," codes INSEE dans Rpicoms\n";
      $nbreFeatures = ['Features'=> 0, 'codeInseeAvec1Feature'=> 0];
      foreach ($this->rpicoms as $rpicom)
        $nbreFeatures = $rpicom->nbreFeatures($nbreFeatures);
      echo Yaml::dump(['$nbreFeatures'=> $nbreFeatures]);
      Node::showStats();
    }
  }
  
  function testGeoLoc(IndGeoJFile $igeojfile, array &$stats): void {
    // Test de la la geolocalisabilité
    foreach ($this->rpicoms as $rpicom)
      $rpicom->testGeoLoc($igeojfile, $this, $stats);
  }
  
  function geoloc(IndGeoJFile $igeojfile, GeoJFileW $geojfilew, array &$nbs): void {
    $nbrecords = 0;
    $nbAVoirs = 0;
    $nbErreurs = 0;
    foreach ($this->rpicoms as $id => $rpicom) {
      $rpicom->geoloc($this, $igeojfile, $geojfilew, $nbs);
      if ($nbs['records'] > 100) break;
    }
  }
};

// correspond à un extrait du Rpicom pour un code INSEE
// contrairement au Yaml la date utilisée comme clé des versions est la date de début et non la date de fin de la version
class Rpicom2 {
  protected $id; // string - id du rpicom dans rpicoms
  protected $versions=[]; // [ start => (Version2 | EvtCreation) ]
  
  // prend un array tel que stocké en Yaml
  function __construct(string $id, array $yaml) {
    $this->id = $id;
    // crée les versions indexées sur la date de fin comme dans Yaml
    $versions = []; // versions indexés sur end
    foreach ($yaml as $end => $version) {
      if (isset($version['name']))
        $versions[$end] = new Version2($id, $end, $version);
      else
        $versions[$end] = new EvtCreation($id, $end, $version);
    }
    // complète chaque version par la date et l'evt de début
    // et construit le tableau indexé sur la date de début ou '0'
    $ends = array_keys($versions);
    foreach ($ends as $no => $end) {
      if (isset($ends[$no+1])) {
        $start = $ends[$no+1];
        $startEvt = $versions[$start]->endEvt();
      }
      else {
        $start = '0';
        $startEvt = null;
      }
      $versions[$end]->setStart($start, $startEvt);
      $this->versions[$start] = $versions[$end];
    }
  }
  
  function finalize(Rpicoms $rpicoms) {
    if (0)
      foreach ($this->versions as $dv => $version)
        $version->finalize($rpicoms);
  }
  
  function mostRecent(): Version2 { return array_values($this->versions)[0]; }
  
  // retourne la date de la version précédent la version $startRef
  function previousVersionDate(string $startRef): string {
    $keys = array_keys($this->versions);
    foreach ($keys as $no => $start) {
      if (($start == $startRef) && isset($keys[$no+1]))
        return $keys[$no+1];
    }
    return '';
  }
  
  // retourne la date de début de la version finissant à la date indiquée
  function startOfVersionEnding(string $endRef): string {
    foreach ($this->versions as $start => $version) {
      if ($version->end() == $endRef)
        return $start;
    }
    return '';
  }
    
  // affichage du dernier nom barré s'il n'est plus valide
  function __toString(): start {
    $mostRecent = $this->mostRecent();
    $end = $mostRecent->end();
    return (($end<>'now') ? '<s>' : '').$mostRecent->name()." ($this->id)".(($end<>'now') ? '</s>' : '');
  }
  
  // renvoie la version correspondant à la clé ou null 
  function __get(string $start) {
    if (isset($this->versions[$start]))
      return $this->versions[$start];
    else
      return null;
  }
  
  function asArray(): array {
    $rpicom = [];
    foreach ($this->versions as $start => $version) {
      $end = $version->end();
      $rpicom["$start/$end"] = $version->asArray();
    }
    return $rpicom;
  }
  
  // affiche les Evts de création initiale définis à la date la plus ancienne du Rpicom
  function coms1943() {
    echo Yaml::dump([$this->id => $this->asArray()]);
    $firstdv = array_reverse(array_keys($this->versions))[0];
    echo "    firstdv: $firstdv\n";
    if (get_class($this->versions[$firstdv]) == 'EvtCreation')
      echo "<b>Création après 1943</b>\n";
  }
  
  function nbreFeatures(array $nbres): array {
    $nbFeatures = 0;
    foreach ($this->versions as $dv => $version) {
      if (get_class($version) == 'Version2')
        $nbFeatures++;
    }
    $nbres['Features'] += $nbFeatures;
    if ($nbFeatures == 1)
      $nbres['codeInseeAvec1Feature'] += 1;
    return $nbres;
  }
  
  function setChildren(Rpicoms $rpicoms) {
    foreach ($this->versions as $dv => $version)
      $version->setChildren($rpicoms);
  }
  
  function buildInclusionGraph(Rpicoms $rpicoms) {
    // n'intègre pas dans le graphe les Rpicom n'ayant qu'une version courante
    if ((count($this->versions) == 1) && ($this->versions[0]->end() == 'now')) {
      //echo "exclut $this->id du graphe\n";
      //echo Yaml::dump([$this->id => $this->asArray()]);
      return;
    }
    foreach ($this->versions as $dv => $version)
      $version->buildInclusionGraph($rpicoms);
  }
  
  function testGeoLoc(IndGeoJFile $igeojfile, Rpicoms $rpicoms, array &$stats): void {
    foreach ($this->versions as $start => $version)
      $version->testGeoloc($igeojfile, $rpicoms, $stats);
  }
  
  function geoloc(Rpicoms $rpicoms, IndGeoJFile $igeojfile, GeoJFileW $geojfilew, array &$nbs): void {
    {/*
    $dvs = array_keys($rpicom);
    $datasets = $igeojfile->datasets($id);
    $dataset = []; // ['S'+'R'=> [datasetId, overEstim]]
    foreach ($dvs as $nodv => $dv) {
      $version = $rpicom[$dv];
      if (!isset($version['name'])) continue; // si pas de nom alors ce n'est pas une version mais uniq. un évt.
      $start = $dvs[$nodv+1] ?? '1943-01-01';
      $idv = "$id@$start";
      $endEvt = Version::shortEvt($version);
      $dataset = defDataset($rpicoms, $id, $dv, $datasets, $dataset);
      $record = [
        'vid'=> $idv,
        'comid'=> $id,
        'type'=> Version::type($version),
        'parent'=> Version::parent($version),
        'start'=> $start,
        'startEvt'=> isset($dvs[$nodv+1]) ? Version::shortEvt($rpicom[$dvs[$nodv+1]]) : '',
        'end'=> ($dv == 'now') ? '9999-12-31' : $dv,
        'endEvt'=> $endEvt,
        'name'=> $version['name'],
        'overEstim'=> Version::type($version) == 'COMS' ? $dataset['S'][1] : $dataset['R'][1],
        'dataset'=> Version::type($version) == 'COMS' ? $dataset['S'][0] : $dataset['R'][0],
      ];
      echo Yaml::dump([$idv => $record]);
      if (!$geojfilew->geoloc($igeojfile, $record))
        $nbErreurs++;
      if ($record['dataset'] == 'A VOIR')
        $nbAVoirs++;
      $nbrecords++;
      if (isset($version['commeDéléguée'])) {
        $idv = "${id}D@$start";
        $record = [
          'vid'=> $idv,
          'comid'=> $id,
          'type'=> 'COMD',
          'parent'=> $id,
          'start'=> $start,
          'startEvt'=> isset($dvs[$nodv+1]) ? Version::shortEvt($rpicom[$dvs[$nodv+1]]) : '',
          'end'=> ($dv == 'now') ? '9999-12-31' : $dv,
          'endEvt'=> Version::shortEvt($version),
          'name'=> $version['commeDéléguée']['name'],
          'overEstim'=> $dataset['R'][1],
          'dataset'=> $dataset['R'][0],
        ];
        echo Yaml::dump([$idv => $record]);
        if (!$geojfilew->geoloc($igeojfile, $record))
          $nbErreurs++;
        if ($record['dataset'] == 'A VOIR')
          $nbAVoirs++;
        $nbrecords++;
      }
      //if ($nbrecords >= 1000) break 2;
    }
    */}
    $datasets = $igeojfile->datasets($this->id);
    $dataset = []; // ['S'+'R'=> [datasetId, overEstim]]
    foreach ($this->versions as $version) {
      $version->geoloc($rpicoms, $igeojfile, $geojfilew, $nbs, $datasets, $dataset);
      if ($nbs['records'] > 100) break;
    }
  }
};

// évènement
class Evt2 {
  // simplifification des libellés
  const SIMPLES = [
    "Entre dans le périmètre du Rpicom" => "entreDansRpicom",
    "Sort du périmètre du Rpicom" => "sortDuRpicom",
    "Se crée en commune nouvelle avec commune déléguée propre" => 'seCréeEnComNouvAvecDélPropre',
    "Se crée en commune nouvelle" => 'seCréeEnComNouvelle',
    "Prend des c. associées et/ou absorbe des c. fusionnées" => 'PrendAssocOuAbsorbe',
    "Commune rétablissant des c. rattachées ou fusionnées" => "rétablitCommunesRattachéesOuFusionnées",
  ];
  protected $evt; // soit un libellé soit un array de la forme [key => val]
  
  function __construct($evt) { $this->evt = $evt; }
  
  // dans le cas d'un array la valeur contenue, sinon '', peut être un string ou un array
  function cible() { return is_array($this->evt) ? array_values($this->evt)[0] : ''; }
    
  function value() { return $this->evt; }
 
  function __toString(): string { // encodage court de l'évènement
    if (is_array($this->evt)) {
      $key = array_keys($this->evt)[0];
      if ($key == 'changeDeNomPour')
        return 'changeDeNom';
      else
        return str_replace(['"',':'], ['', ': '], json_encode($this->evt, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    else
      return self::SIMPLES[$this->evt] ?? $this->evt;
  }
  
  function str(): string { return $this->__toString(); }
  
  function type(): string { // type d'evt défini soit par la clé, soit par le libellé simplifié
    if (is_string($this->evt))
      return self::SIMPLES[$this->evt] ?? $this->evt;
    elseif (array_keys($this->evt)[0] == 'changeDeNomPour')
      return 'changeDeNom';
    else
      return array_keys($this->evt)[0];
  }
};

//abstract class VersionOrCreationEvt {};

{/*PhpDoc:
name: Version2
title: class Version2 - une version courante ou historique avec un intervalle durant lequel les infos sont valides
doc: |
  Une version est identifiée par la chaine codeInsee@dateDeDébut
  2 relations spatiales entre versions sont construites par buildInclusionGraph() à partir des évènements :
    - l'identité géométrique au travers d'une relation d'équivalence définie par son quotient
      une version est quotient ou non pour cette relation
      si elle l'est alors $quotient=='' et $equals contient l'ens. des id des objets ayant même géométrie
      si elle ne l'est pas alors $quotient contient l'id du quotient et $equals est vide
      La mise à jour s'effectue par addEquals($id) qui statut que this et $id sont identiques
    - l'inclusion entre versions 
*/}
class Version2 {
  protected $id; // string - id du rpicom (code INSEE)
  protected $start; // string - date de début ou '' si valide depuis le début du référentiel
  protected $startEvt; // evt de début ou null si valide depuis le début du référentiel
  protected $end; // string - date de fin ou 'now' si version courante
  protected $endEvt; // evt de fin ou null si version courante
  protected $name; // string
  protected $type; // string - {'COMA'|'COMD'|'ARM'|'COMS'}
  protected $parent; // string - l'id du parent
  protected $children; // [ string ]
  protected $commeDéléguée; // VComDP ou null
  protected $geolocDataset;

  // construit partiellement à partir du Yaml et de la date de fin
  function __construct(string $id, string $end, array $version) {
    $this->id = $id;
    $this->start = '';
    $this->startEvt = null;
    $this->end = $end;
    $this->endEvt = isset($version['évènement']) ? new Evt2($version['évènement']) : null;
    $this->name = $version['name'];
    if (isset($version['estAssociéeA'])) {
      $this->type = 'COMA';
      $this->parent = $version['estAssociéeA'];
    }
    elseif (isset($version['estDéléguéeDe'])) {
      $this->type = 'COMD';
      $this->parent = $version['estDéléguéeDe'];
    }
    elseif (isset($version['estArrondissementMunicipalDe'])) {
      $this->type = 'ARM';
      $this->parent = $version['estArrondissementMunicipalDe'];
    }
    else {
      $this->type = 'COMS';
      $this->parent = '';
    }
    $this->children = [];
    if (isset($version['commeDéléguée']))
      $this->commeDéléguée = new VComDP($this, $id.'D', $this->end, $this->endEvt, $version['commeDéléguée']);
  }
  
  // complète l'objet avec la date et l'evt de début
  function setStart(string $start, ?Evt2 $startEvt) {
    $this->start = $start;
    $this->startEvt = $startEvt;
    if ($this->commeDéléguée)
      $this->commeDéléguée->setStart($start, $startEvt);
  }
  
  function __toString() { return "$this->name ($this->id@$this->start)"; }
  
  function name() { return $this->name; }
  function start() { return $this->start; }
  function end() { return $this->end; }
  function endEvt() { return $this->endEvt; }
  
  function asArray() {
    $array = [];
    if ($this->endEvt) $array['endEvt'] = $this->endEvt->value();
    //if ($this->end) $array['end'] = $this->end;
    $array['name'] = $this->name;
    $array['type'] = $this->type;
    if ($this->parent) $array['parent'] = $this->parent;
    if ($this->children) $array['children'] = $this->children;
    if ($this->commeDéléguée) $array['commeDéléguée'] = $this->commeDéléguée->asArray();
    if ($node = Node::get("$this->id@$this->start")) $array['spatialRelations'] = $node->asArray();
    $array['geolocDataset'] = $this->geolocDataset;
    if ($this->startEvt) $array['startEvt'] = $this->startEvt->value();
    //if ($this->start) $array['start'] = $this->start;
    return $array;
  }

  function ABANDONNEE_setChildren(Rpicoms $rpicoms) { // ABANDONNEE
    /*if ($this->parent) {
      $parentId = $this->parent.'@'.$this->start;
      $parent = $rpicoms->$parentId;
      $parent->children[] = $this->id;
    }*/
  }
  
  function buildInclusionGraph(Rpicoms $rpicoms) {
    // je gère les relations spatiales entre codes INSEE au travers des états
    $vn = Node::goc("$this->id@$this->start"); // le noeud correspondant à cette version
    if ($this->parent) {
      $parentVid = $this->parent.'@'.$this->start; // hypothèse qu'il y a toujours une version parent à la même date
      $parent = $rpicoms->$parentVid;
      if ($parent)
        $vn->within($parentVid);
    }
    
    // j'utilise l'endEvt pour gérer les relations spatiales entre versions successives dans le rpicom
    if ($this->endEvt) {
      switch ($this->endEvt->type()) {
        // ne change pas la géométrie
        case 'reçoitUnePartieDe': // simplification
        case 'contribueA': // simplification
        
        case 'resteAssociéeA':
        case 'resteDéléguéeDe':
        case 'changedAssociéeEnDéléguéeDe':
        case 'Absorbe certaines de ses c. rattachées ou certaines de ses c. associées deviennent déléguées':
        case 'changeDeNom': {
          $vn->equals("$this->id@$this->end"); // l'entité avant est identique à celle d'après
          return;
        }
        case 'seFondDans':
        case 'fusionneDans': {
          $vn->within($this->endEvt->cible().'@'.$this->end); // la fusionnée est dans la fusionnante
          return;
        }
        case 'devientDéléguéeDe':
        case 'sAssocieA': {
          $vn->equals("$this->id@$this->end"); // une association ne change pas la géométrie de l'associée
          $vn->within($this->endEvt->cible().'@'.$this->end); // l'associée est dans l'associante
          return;
        }
        case 'seCréeEnComNouvelle': {
          $vn->within("$this->id@$this->end"); // l'ancienne commune est dans la nouvelle
          return;
        }
        case 'seCréeEnComNouvAvecDélPropre': {
          $vn->within("$this->id@$this->end"); // l'ancienne commune est dans la nouvelle
          $vn->equals($this->id."D@$this->end"); // l'ancienne commune equals la commune déléguée propre
          return;
        }
        case 'PrendAssocOuAbsorbe': {
          $vn->within("$this->id@$this->end"); // l'ancienne commune est dans la nouvelle
          return;
        }
        case 'Commune déléguée rétablie comme commune simple':
        case 'Commune associée rétablie comme commune simple': {
          $vn->equals("$this->id@$this->end");
          return;
        }
        case 'sortDuRpicom': return; // ne rien faire
        
        case 'rétablitCommunesRattachéesOuFusionnées': {
          $vn->contains("$this->id@$this->end"); // l'ancienne commune contient la nouvelle
          return;
        }
        
        case 'changeDeRattachementPour':
        case 'perdRattachementPour':
        case 'Commune rattachée devient commune de rattachement': {
          // A VOIR
          return;
        }
        case 'quitteLeDépartementEtPrendLeCode': {
          $vn->equals($this->endEvt->cible().'@'.$this->end); // même feature entre les 2 départements
          //echo "$this->id@$this->start equals ",$this->endEvt->cible(),"@$this->end\n";
          return;
        }
        case 'seDissoutDans': return; // je ne sais pas le traiter
        
        default: {
          //echo Yaml::dump(Node::allAsArray());
          echo "<b>A faire buildInclusionGraph pour type=".$this->endEvt->type()." :</b>\n";
          echo Yaml::dump(['$id'=> $this->id, '$start'=> $this->start, 'rpicom'=> $rpicoms->{$this->id}->asArray()], 3, 2);
          throw new Exception("A faire buildInclusionGraph pour type=".$this->endEvt->type());
        }
      }
    }
  }
  
  // retourne le nom du dataset le plus récent géolocalisant cet objet ou '' s'il n'y en a pas
  function possibleDataset(IndGeoJFile $igeojfile): string {
    $validDatasets = Datasets::validBetween($this->start, $this->end); // les datasets pertinents du point de vue date
    if ($validDatasets) {
      $indexDatasets = $igeojfile->datasets($this->id); // les datasets dans lesquels l'id est défini
      if ($possibleDatasets = array_intersect($validDatasets, $indexDatasets)) {
        //echo Yaml::dump(['$possibleDatasets'=> $possibleDatasets]);
        return array_values($possibleDatasets)[0];
      }
    }
    return '';
  }
  
  // recherche d'un majorant de $this
  // retourne le dataset dans lequel je trouve un majorant de $this ainsi que le vid majorant défini dans ce dataset
  // retourne [datasetId, overEstimVid] avec overEstimVid défini dans datasetId
  function overEstim(IndGeoJFile $igeojfile, Rpicoms $rpicoms, int $recursiveCounter=0): array {
    //echo str_repeat("* ", $recursiveCounter),"$this->id@$this->start ->overEstim()\n";
    if (!($n = Node::get("$this->id@$this->start"))) {
      echo "$this->id@$this->start absent du graphe d'inclusion\n";
      return [];
    }
    foreach ($n->ids() as $vid) { // les vid equals $this
      if (!($eq = $rpicoms->$vid))
        throw new Exception("Erreur d'utilisation de $vid");
      if (get_class($eq) <> 'Version2')
        throw new Exception("Erreur d'appel de possibleDataset() sur $vid qui n'est pas Version2");
      if ($possibleDataset = $eq->possibleDataset($igeojfile))
        return [$possibleDataset, $vid];
    }
    if (!($containings = $n->containing())) { // si aucun objet ne contient $this
      echo str_repeat("* ", $recursiveCounter), "aucun objet ne contient $this->id@$this->start\n";
      //if ($recursiveCounter==0) die();
      return [];
    }
    foreach ($containings as $containing) // les noeuds contenant $this
      foreach ($containing->ids() as $containingVid) // les vid contenant $this
        if ($possibleDataset = $rpicoms->$containingVid->possibleDataset($igeojfile))
          return [$possibleDataset, $containingVid];
    if ($recursiveCounter > 100)
      throw new Exception("Erreur de boucle dans Version2::overEstim()");
    foreach ($containings as $containing) // les noeuds contenant $this
      foreach ($containing->ids() as $containingVid) // les vid contenant $this
        if ($overEstim = $rpicoms->$containingVid->overEstim($igeojfile, $rpicoms, $recursiveCounter+1))
          return $overEstim;
    return [];
  }

  function testGeoLoc(IndGeoJFile $igeojfile, Rpicoms $rpicoms, array &$stats): void {
    //echo "$this->id@$this->start ->testGeoLoc()\n";
    if ($this->end == 'now') {
      $this->geolocDataset = ($this->type == 'COMS') ? 'Ae2020Cog' : 'Ae2020CogR';
      $stats['geolocalisé']++;
    }
    elseif ($possibleDataset = $this->possibleDataset($igeojfile)) {
      $this->geolocDataset = $possibleDataset;
      $stats['geolocalisé']++;
    }
    elseif ($overEstim = $this->overEstim($igeojfile, $rpicoms)) {
      $this->geolocDataset = $overEstim;
      $stats['majoré']++;
    }
    else {
      $this->geolocDataset = '<b>AUCUN</b>';
      $stats['erreur']++;
    }
  }
  
  // retourne la provenance et le type de la géométrie sous la forme ['S'+'R'=> [datasetId, overEstim]]
  //  / datasetId est l'un des identifiants de dataset définis dans IndGeoJFile
  //  / overEstim vaut '' si la géométrie est disponible, sinon l'id d'un majorant dans le dataset
  // $previous est le retour effectué pour une version ultérieure du même id
  function defDataset(Rpicoms $rpicoms, array $datasets, array $previous): array {
    if ($this->end == 'now') { // si la version existe alors la géométrie est définie dans Ae2020Cog ou Ae2020CogR
      // je traite de plus le bug IGN des entités absentes par du Cog en utilisant alors la v. Ae2019
      return [
        'S'=> in_array('Ae2020Cog', $datasets) ? ['Ae2020Cog', '']
            : (in_array('Ae2019Cog', $datasets) ? ['Ae2019Cog', ''] : ['Erreur', '']),
        'R'=> in_array('Ae2020CogR', $datasets) ? ['Ae2020CogR', ''] : ['Erreur', ''],
      ];
    }
    elseif ($this->endEvt->type() == 'changeDeNom') { // pas de chgt de géométrie
      return $previous;
    }
    elseif ($this->endEvt->type() == 'fusionneDans') { // cas d'une entité qui fusionne
      if ($this->type == 'COMS') { // cas d'une COMS qui fusionne
        
      }
      elseif (strcmp($this->end, '2003-01-01') < 0) { // cas d'une COMA/COMD qui fusionne avant 2003 => pas de réf. => majorant
        return ['S'=> ['A VOIR',''], 'R'=> ['A VOIR','']];
      }
      else { // cas d'une COMA/COMD qui fusionne après 2003
        
      }
    }
    elseif ($this->endEvt->type() == 'sAssocieA') { // cas d'une COMS qui sAssocieA
      if (strcmp($this->end, '2003-01-01') < 0) { // association avant 2003 => pas de réf. => majorant
        return ['S'=> ['A VOIR',''], 'R'=> ['A VOIR','']];
      }
      else { // association après 2003
        
      }
    }
    elseif ($this->endEvt->type() == 'seCréeEnComNouvAvecDélPropre') {
      if ($dataset = Datasets::between($datasets, $this->start, $this->end)) {
        return ['S'=> [$dataset, '']];
      }
      else { // avant 2003 => pas de réf. => majorant
        return ['S'=> ['A VOIR',''], 'R'=> ['A VOIR','']];
      }
    }
    else {
      //return ['S'=> ['A VOIR',''], 'R'=> ['A VOIR','']];
    }
    $id = $this->id;
    $rpicom = $rpicoms->$id->asArray();
    echo Yaml::dump(['defDataset'=> ['$id'=> $id, '$end'=> $this->end, '$rpicom'=> $rpicom, '$datasets'=> $datasets]], 4);
    throw new Exception("A VOIR");

    {/*
    if (Version::evt($version) == 'fusionneDans') { // cas d'une entité qui fusionne
      if (!isset($version['estAssociéeA']) && !isset($version['estDéléguéeDe'])) { // cas d'une COM qui fusionne
        if ($dataset = Datasets::mostRecentEarlierCSDataset($dvref, $datasets))
          return ['S'=> [$dataset, '']];
        else
          return ['S'=> overEstim($rpicoms, $version['évènement']['fusionneDans'], $dvref)];
      }
      elseif (strcmp($dvref, '2003-01-01') < 0) { // cas d'une COMA/COMD qui fusionne avant 2003 => pas de réf. => majorant
        return ['R'=> overEstim($rpicoms, $version['évènement']['fusionneDans'], $dvref)];
      }
      else { // date de fusion après 2003
        // recherche une éventuelle version précédente correspondant à l'association ou devenueDéléguée
        $dvprec = null;
        foreach ($rpicom as $dv => $v) { // recherche de la version précédente à $dvref
          if ((strcmp($dv, $dvref) < 0) && (Version::evt($v) <> 'resteAssociéeA')) {
            // première version antérieure à $dvref <> resteAssociéeA
            $dvprec = $dv;
            break;
          }
        }
        if (!$dvprec) // si pas de version précédente, ex association avant 1943
          return ['R'=> overEstim($rpicoms, $version['évènement']['fusionneDans'], $dvref)]; // => majorant
        elseif (isset($rpicom[$dvprec]['évènement']['sAssocieA']) || isset($rpicom[$dvprec]['évènement']['devientDéléguéeDe'])) {
          // si la version précédente est une sAssocieA ou une devientDéléguéeDe
          if ($dataset = Datasets::mostRecentEarlierCSDataset($dvprec, $datasets))
            return ['R'=> [$dataset, '']];
          else
            return ['R'=> overEstim($rpicoms, $version['évènement']['fusionneDans'], $dvref)];
        }
      }
    }
    elseif (Version::evt($version) == 'sAssocieA') { // cas d'une COMS qui s'associe à une autre
      if ($dataset = Datasets::mostRecentEarlierCSDataset($dvref, $datasets))
        return ['S'=> [$dataset, '']];
      else // date d'assos avant 2003 => pas de référentiel => majorant
        return ['S'=> overEstim($rpicoms, $version['évènement']['sAssocieA'], $dvref)];
    }
    elseif (Version::evt($version) == 'Se crée en commune nouvelle avec commune déléguée propre') {
      if ($dataset = Datasets::mostRecentEarlierCSDataset($dvref, $datasets))
        return ['S'=> [$dataset, '']];
    }
    elseif (Version::evt($version) == 'Prend des c. associées et/ou absorbe des c. fusionnées') {
      if ($dataset = Datasets::mostRecentEarlierCSDataset($dvref, $datasets))
        return ['S'=> [$dataset, '']];
      else
        return ['S'=> overEstim($rpicoms, $id, $dvref)];
    }
    else {
      echo Yaml::dump(['defDataset'=> ['$id'=> $id, '$dvref'=> $dvref, '$rpicom'=> $rpicom]], 4);
      throw new Exception("A VOIR");
      //return ['S'=> ['A VOIR',''], 'R'=> ['A VOIR','']];
    }
    */}
  }
  
  function geoloc(Rpicoms $rpicoms, IndGeoJFile $igeojfile, GeoJFileW $geojfilew, array &$nbs, array $datasets, array &$dataset): void {
    $start = $this->start ? $this->start : '1943-01-01';
    $dataset = $this->defDataset($rpicoms, $datasets, $dataset);
    $record = [
      'vid'=> "$this->id@$start",
      'comid'=> $this->id,
      'type'=> $this->type,
      'parent'=> $this->parent,
      'start'=> $start,
      'startEvt'=> $this->startEvt ? $this->startEvt->__toString() : '',
      'end'=> ($this->end == 'now') ? '9999-12-31' : $this->end,
      'endEvt'=> $this->endEvt ? $this->endEvt->__toString() : '',
      'name'=> $this->name,
      'overEstim'=> ($this->type == 'COMS') ? $dataset['S'][1] : $dataset['R'][1],
      'dataset'=> ($this->type == 'COMS') ? $dataset['S'][0] : $dataset['R'][0],
    ];
    echo Yaml::dump([$record['vid'] => $record]);
    if (!$geojfilew->geoloc($igeojfile, $record))
      $nbs['erreurs']++;
    if ($record['dataset'] == 'A VOIR')
      $nbs['aVoirs']++;
    $nbs['records']++;
    if ($this->commeDéléguée) {
      $record = [
        'vid'=> $this->id."D@$start",
        'comid'=> $this->id,
        'type'=> 'COMD',
        'parent'=> $this->id,
        'start'=> $start,
        'startEvt'=> $this->startEvt ? $this->startEvt->__toString() : '',
        'end'=> ($this->end == 'now') ? '9999-12-31' : $this->end,
        'endEvt'=> $this->endEvt ? $this->endEvt->__toString() : '',
        'name'=> $this->commeDéléguée['name'],
        'overEstim'=> $dataset['R'][1],
        'dataset'=> $dataset['R'][0],
      ];
      echo Yaml::dump([$record['vid'] => $record]);
      if (!$geojfilew->geoloc($igeojfile, $record))
        $nbs['erreurs']++;
      if ($record['dataset'] == 'A VOIR')
        $nbs['AVoirs']++;
      $nbs['records']++;
    }
  }
};

// version de commune déléguée propre
class VComDP {
  protected $id; // string - id de la commune déléguée propre
  protected $start; // string - date de début ou '' si valide depuis le début du référentiel
  protected $startEvt; // evt de début ou null si valide depuis le début du référentiel
  protected $end; // string - date de fin ou 'now' si version courante
  protected $endEvt; // evt de fin ou null si version courante
  protected $name; // string - nom comme c. déléguée
  protected $parent; // Version2

  function __construct(Version2 $parent, string $idd, string $end, ?Evt2 $endEvt, array $version) {
    $this->id = $idd;
    $this->end = $end;
    $this->endEvt = $endEvt;
    $this->name = $version['name'];
    $this->parent = $parent;
  }
  
  function setStart(string $start, Evt2 $startEvt) {
    $this->start = $start;
    $this->startEvt = $startEvt;
  }
  
  function __toString() { return "$this->id@$this->end"; }
  
  function asArray(): array {
    $array = [];
    //if ($this->endEvt) $array['endEvt'] = $this->endEvt->value();
    //if ($this->end) $array['end'] = $this->end;
    $array['name'] = $this->name;
    //if ($this->startEvt) $array['startEvt'] = $this->startEvt->value();
    //if ($this->start) $array['start'] = $this->start;
    return $array;
  }
};

// Evt de création
class EvtCreation {
  protected $id; // le code INSEE
  protected $start; // string - date de destruction précédente ou ''
  protected $startEvt; // evt de destruction ou null
  protected $end; // string - date de création ou de fin d'interruption
  protected $endEvt; // evt de création ou de fin d'interruption

  function __construct(string $id, string $end, array $version) {
    $this->id = $id;
    $this->end = $end;
    $this->endEvt = new Evt2($version['évènement']);
  }
  
  function setStart(string $start, ?Evt2 $startEvt) {
    $this->start = $start;
    $this->startEvt = $startEvt;
  }
  
  function start() { return $this->start; }
  function end() { return $this->end; }
  function endEvt() { return $this->endEvt; }
  
  function asArray() {
    $array = [];
    //if ($this->endEvt) $array['endEvt'] = $this->endEvt->value();
    if ($this->startEvt && $this->endEvt)
      $array['statut'] = '---------------- INTERRUPTION ----------------';
    elseif ($this->endEvt)
      $array['statut'] = '---------------- CREATION ----------------';
    else
      $array['statut'] = '---------------- DESTRUCTION ----------------'; // jamais utilisé
    return $array;
  }

  function setChildren(Rpicoms $rpicoms) {} // pas applicable
  
  function buildInclusionGraph(Rpicoms $rpicoms) {
    if ($this->endEvt) {
      switch ($this->endEvt->type()) {
        case 'entreDansRpicom': return; // ne rien à faire
        
        case 'arriveDansLeDépartementAvecLeCode': return; // ne rien à faire, géré par quitteLeDépartementEtPrendLeCode
        
        case 'rétablieCommeSimpleDe':
        case 'rétablieCommeAssociéeDe': {
          // la commune qui est rétablie $this->id@$this->end est dans la commune dont elle provient
          Node::goc("$this->id@$this->end")->within($this->endEvt->cible().'@'.$this->start);
          if ($this->startEvt) { // si évt antérieur de supression, la version avant supression et après rétablissement sont identiques
            if ($previousVersionDate = $rpicoms->{$this->id}->previousVersionDate($this->start))
            //echo "$this->id@$this->end equals $this->id@$previousVersionDate\n";
              Node::goc("$this->id@$this->end")->equals("$this->id@$previousVersionDate");
          }
          return;
        }
        case 'crééeParFusionSimpleDe': {
          $vEnd = Node::goc("$this->id@$this->end");
          foreach ($this->endEvt->cible() as $cible) {
            if ('' !== ($start = $rpicoms->$cible->startOfVersionEnding($this->end))) {
              //echo "$this->id@$this->end contains $cible@$start\n";
              Node::goc("$this->id@$this->end")->contains("$cible@$start");
            }
          }
          return;
        }
        case 'crééeAPartirDe': {
          return;
        }
        case 'rétabliCommeArrondissementMunicipalDe': {
          return;
        }
        default: {
          echo "<b>A faire buildInclusionGraph pour type=".$this->endEvt->type()." :</b>\n";
          echo Yaml::dump(['$id'=> $this->id, '$start'=> $this->start, 'rpicom'=> $rpicoms->{$this->id}->asArray()], 3, 2);
          throw new Exception("A faire buildInclusionGraph pour type=".$this->endEvt->type());
        }
      }
    }
  }
  
  function testGeoLoc(IndGeoJFile $igeojfile, Rpicoms $rpicoms, array &$stats): void { $stats['erreur']++; } // pas applicable
};

