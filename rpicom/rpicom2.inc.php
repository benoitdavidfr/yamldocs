<?php
/*PhpDoc:
name: rpicom2.inc.php
title: rpicom2.inc.php - production d'une version géolocalisée du Rpicom
doc: |

journal: |
  6/5/2020:
    - dév. V2 de geoloc
      - géocoder les versions pour lesquelles un référentiel est disponible
        et les versions géom. identiques aux précédentes (40015 + 3464 / 46274 = 94%)
      - trouver un majorant (2339 / 46274 = 5%)
      - erreurs (456 / 46274 = 1%)
    - génération d'un fichier SHP avec QGis
    - améliorations à étudier
      - qqs libellés d'évts à raccourcir
      - rajouter la génération des déléguées propres
      - traiter certaines erreurs de géocodage en construisant la géométrie des unions
      - relire le code et mieux le documenter
      - réflechir à l'utilité de découper le JD en 4
      - étudier les cas de rétablissement
  2/5/2020:
    - création
includes:
classes:
functions:
*/
require_once __DIR__.'/feature.inc.php';
require_once __DIR__.'/funexp.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// correspond au contenu du Rpicom
class Rpicoms {
  protected $rpicoms; // [ id => Rpicom2 ]
  
  function __construct(array $rpicoms) {
    foreach ($rpicoms as $id => $rpicom)
      $this->rpicoms[$id] = new Rpicom2($id, $rpicom);
  }
  
  // renvoit si $key est un code INSEE alors le Rpicom sinon s'il est de la forme {id}@{versionKey} alors la version sinon null
  // si $key est de la forme {id}D@{versionKey} alors renvoit la version de déléguée propre de {id}@{versionKey}
  function __get(string $key) {
    if (isset($this->rpicoms[$key]))
      return $this->rpicoms[$key];
    if (false === $pos = strpos($key, '@'))
      throw new Exception("Erreur d'accès dans Rpicoms sur $key");
    $id = substr($key, 0, $pos);
    $start = substr($key, $pos+1);
    if (substr($id, -1) == 'D') {
      $id = substr($id, 0, -1);
      //echo "id=$id\n";
      if (!isset($this->rpicoms[$id]))
        throw new Exception("Erreur d'accès dans Rpicoms sur $key");
      $cratv = $this->rpicoms[$id]->$start;
      return $cratv->commeDeleguee();
    }
    if (!isset($this->rpicoms[$id]))
      throw new Exception("Erreur d'accès dans Rpicoms sur $key");
    return $this->rpicoms[$id]->$start;
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
  
  function detecteErreurs(): void {
    foreach ($this->rpicoms as $rpicom)
      $rpicom->detecteErreurs($this);
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
      Feature::showStats();
    }
    //Feature::show();
  }
  
  function testGeoLoc(IndGeoJFile $igeojfile): void {
    // Estimation du nbre de features geolocalisables
    $stats = ['exactNow'=> 0, 'exactBestDs'=> 0, 'substitut'=> 0, 'majoré'=> 0, 'nonGéoloc'=> 0, 'notAFeature'=> 0];
    foreach ($this->rpicoms as $rpicom)
      $rpicom->detailleRetablissement($this);
    foreach ($this->rpicoms as $rpicom)
      $rpicom->construitExprFonc($this);
    foreach ($this->rpicoms as $rpicom)
      $rpicom->testGeoLoc($igeojfile, $this, $stats);
    echo Yaml::dump(['statsTestGeoLoc'=> $stats]),"\n";
  }
  
  function geoloc(IndGeoJFile $igeojfile, GeoJFileW $geojfilew): array {
    $nbs = [
      'records' => 0,
      'aVoirs' => 0,
      'erreurs' => 0,
      'nbreFeatures' => 0,
    ];
    foreach ($this->rpicoms as $id => $rpicom) {
      $rpicom->geoloc($this, $igeojfile, $geojfilew, $nbs);
      //if ($nbs['records'] > 1000) break;
    }
    return $nbs;
  }
};

// correspond à un extrait du Rpicom pour un code INSEE
// contrairement au Yaml la date utilisée comme clé des versions est la date de début et non la date de fin de la version
class Rpicom2 {
  protected $id; // string - id du rpicom dans rpicoms
  protected $versions=[]; // [ start => (Version2 | EvtCreation) ]
  
  // prend l'id et l'array tel que stockés en Yaml
  function __construct(string $id, array $yaml) {
    $this->id = $id;
    // crée les versions indexées sur la date de fin comme dans le Yaml
    $versions = []; // versions indexés sur end
    foreach ($yaml as $end => $version) {
      if (isset($version['name']))
        $versions[$end] = new Version2($id, $end, $version);
      else
        $versions[$end] = new EvtCreation($id, $end, $version);
    }
    // complète chaque version par la date et l'evt de début
    // et construit le tableau indexé sur la date de début ou '1943'
    $ends = array_keys($versions);
    foreach ($ends as $no => $end) {
      if (isset($ends[$no+1])) {
        $start = $ends[$no+1];
        $startEvt = $versions[$start]->endEvt();
      }
      else {
        $start = '1943';
        $startEvt = null;
      }
      $versions[$end]->setStart($start, $startEvt);
      $this->versions[$start] = $versions[$end];
    }
  }
  
  /*function finalize(Rpicoms $rpicoms) {
    if (0)
      foreach ($this->versions as $dv => $version)
        $version->finalize($rpicoms);
  }*/
  
  // version la plus récente
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
  function __toString(): string {
    $mostRecent = $this->mostRecent();
    $valid = ($mostRecent->end() == 'now');
    return ($valid ? '' : '<s>').$mostRecent->name()." ($this->id)".($valid ? '' : '</s>');
  }
  
  // renvoie la version correspondant à la clé ou null
  function __get(string $key): ?Version2 {
    // Cette clé peut être de la forme
    //   - date pour la version commencant à cette date
    //   - /date pour la version finissant à cette date
    //   - mostRecent pour la version la plus récente
    if ($key == 'mostRecent')
      return array_values($this->versions)[0];
    elseif ((substr($key, 0, 1) == '/') && ($start = $this->startOfVersionEnding(substr($key, 1))))
      return $this->versions[$start];
    elseif (isset($this->versions[$key]))
      return $this->versions[$key];
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
  
  function coms1943() {
    // affiche les Evts de création initiale définis à la date la plus ancienne du Rpicom
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
  
  /*function setChildren(Rpicoms $rpicoms) {
    foreach ($this->versions as $dv => $version)
      $version->setChildren($rpicoms);
  }*/
  
  function detecteErreurs(Rpicoms $rpicoms): void {
    foreach ($this->versions as $dv => $version)
      $version->detecteErreurs($rpicoms);
  }
  
  function buildInclusionGraph(Rpicoms $rpicoms) {
    // n'intègre pas dans le graphe les Rpicom n'ayant qu'une version courante
    if ((count($this->versions) == 1) && ($this->mostRecent()->end() == 'now')) {
      //echo "exclut $this->id du graphe\n";
      //echo Yaml::dump([$this->id => $this->asArray()]);
      return;
    }
    foreach ($this->versions as $dv => $version)
      $version->buildInclusionGraph($rpicoms);
  }
  
  function detailleRetablissement(Rpicoms $rpicoms) {
    // Détaille les évts de rétablissements côté c. de rattachement afin de pouvoir construire ensuite l'expr. fonc. 
    foreach ($this->versions as $dv => $version)
      $version->detailleRetablissement($rpicoms);
  }
  
  function construitExprFonc(Rpicoms $rpicoms) { // construit l'expr. fonctionnelle
    foreach ($this->versions as $dv => $version)
      $version->construitExprFonc($rpicoms);
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
    foreach ($this->versions as $version) {
      $version->geoloc($rpicoms, $igeojfile, $geojfilew, $nbs);
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
  protected $rétablit; // [id => code] - détaille rétablissement côté c. de rattachement, rempli par detailleRetablissement()
  protected $exprFonc; // string - expression fonctionnelle encodée en string
  protected $name; // string
  protected $type; // string - {'COMA'|'COMD'|'ARM'|'COMS'}
  protected $parent; // string - l'id du parent
  //protected $children; // [ string ]
  protected $commeDéléguée; // VComDP ou null
  protected $geolocDataset; // rempli par testGeoLoc(), vaut AUCUN si objet non géolocalisé

  // construit partiellement à partir du Yaml et de la date de fin
  function __construct(string $id, string $end, array $version) {
    $this->id = $id;
    $this->start = '';
    $this->startEvt = null;
    $this->end = $end;
    $this->endEvt = isset($version['évènement']) ? new Evt2($version['évènement']) : null;
    $this->rétablit = [];
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
      $this->commeDéléguée = new VComDP($id, $this->end, $this->endEvt, $version['commeDéléguée']);
  }
  
  // complète l'objet avec la date et l'evt de début
  function setStart(string $start, ?Evt2 $startEvt) {
    $this->start = $start;
    $this->startEvt = $startEvt;
    if ($this->startEvt && ($this->startEvt->str() == 'seCréeEnComNouvAvecDélPropre')) {
      //echo Yaml::dump([$this->id => [$this->start => $this->asArray()]], 3, 2);
      if (!$this->commeDéléguée) {
        //echo "Erreur déléguée propre absente sur $this\n";
        $this->commeDéléguée = new VComDP($this->id, $this->end, $this->endEvt, ['name'=> 'inconnu']);
      }
    }
    if ($this->commeDéléguée)
      $this->commeDéléguée->setStart($start, $startEvt);
  }
  
  // ajoute un détail de rétablissement
  function ajoutRetablit(string $key, string $val): void { $this->rétablit[$key] = $val; }
  
  function vid(): string { return "$this->id@$this->start"; }
  
  function __toString(): string { return "$this->name ($this->id@$this->start)"; }
  
  function name() { return $this->name; }
  function start() { return $this->start; }
  function end() { return $this->end; }
  function endEvt() { return $this->endEvt; }
  function commeDeleguee() { return $this->commeDéléguée; }
  
  function asArray() {
    $array = [];
    if ($this->endEvt) $array['endEvt'] = $this->endEvt->value();
    if ($this->end) $array['end'] = $this->end;
    if ($this->rétablit) $array['rétablit'] = $this->rétablit;
    if ($this->exprFonc) $array['exprFonc'] = $this->exprFonc;
    $array['name'] = $this->name;
    $array['type'] = $this->type;
    if ($this->parent) $array['parent'] = $this->parent;
    if ($this->children) $array['children'] = $this->children;
    if ($this->commeDéléguée) $array['commeDéléguée'] = $this->commeDéléguée->asArray();
    if ($node = Feature::get("$this->id@$this->start")) $array['spatialRelations'] = $node->asArray();
    $array['geolocDataset'] = $this->geolocDataset;
    if ($this->startEvt) $array['startEvt'] = $this->startEvt->value();
    if ($this->start) $array['start'] = $this->start;
    return $array;
  }

  /*function ABANDONNEE_setChildren(Rpicoms $rpicoms) { // ABANDONNEE
    if ($this->parent) {
      $parentId = $this->parent.'@'.$this->start;
      $parent = $rpicoms->$parentId;
      $parent->children[] = $this->id;
    }
  }*/
  
  function detecteErreurs(Rpicoms $rpicoms): void { // 37 Erreurs détectées corrigées dans setStart()
    //évènement: 'Se crée en commune nouvelle avec commune déléguée propre'
    if ($this->startEvt && ($this->startEvt->str() == 'seCréeEnComNouvAvecDélPropre')) {
      //echo Yaml::dump([$this->id => [$this->start => $this->asArray()]], 3, 2);
      if (!$this->commeDéléguée)
        echo "Erreur déléguée propre absente sur $this\n";
    }
  }
  
  function buildInclusionGraph(Rpicoms $rpicoms) {
    try {
      // je gère les relations spatiales entre codes INSEE au travers des états
      $vn = Feature::goc("$this->id@$this->start"); // le Feature correspondant à cette version
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
            //$vn->equals("$this->id@$this->end"); // CA = CS
            //$idR = $this->parent; // id de la c. de rattachement
            //$end = $this->end;
            //$start = $this->start;
            //Feature::goc("$idR@$end")->within("$idR@$start"); // CR' < CR
            //Feature::goc("$this->id@$this->end")->within("$idR@$this->start"); // CS < CR
            return;
          }
          case 'sortDuRpicom': return; // ne rien faire
        
          case 'rétablitCommunesRattachéesOuFusionnées': return; // tout fait côté c. rattachée
        
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
          // je ne sais pas le traiter, il faudrait dire que l'ancien est inclus dans l'union des cibles - Il n'y en a que 6
          case 'seDissoutDans': return; 
        
          default: {
            //echo Yaml::dump(Feature::allAsArray());
            echo "<b>A faire buildInclusionGraph pour type=".$this->endEvt->type()." :</b>\n";
            echo Yaml::dump(['$id'=> $this->id, '$start'=> $this->start, 'rpicom'=> $rpicoms->{$this->id}->asArray()], 3, 2);
            throw new Exception("A faire buildInclusionGraph pour type=".$this->endEvt->type());
          }
        }
      }
    }
    catch(Exception $e) {
      echo $e->getMessage(),"\n",$e->getTraceAsString(),"\n";
      echo Yaml::dump([$this->id => $rpicoms->{$this->id}->asArray(), $this->start => $this->asArray()], 5, 2);
      throw new Exception($e->getMessage());
    }
  }
  
  function detailleRetablissement(Rpicoms $rpicoms) {
    // Détaille les évts de rétablissement côté c. de rattachement afin de pouvoir construire ensuite l'expr. fonc. 
    switch ($this->endEvt) { // je prends comme v. courante celle avant
      case 'Commune déléguée rétablie comme commune simple': {
        $parent = $rpicoms->{"$this->parent@$this->start"};
        //echo "ajout rétablit pour parent=$parent\n"; // object
        $parent->rétablit[$this->id] = 'D2S';
        return;
      }
      case 'Commune associée rétablie comme commune simple': {
        $parent = $rpicoms->{"$this->parent@$this->start"};
        //echo "ajout rétablit pour parent=$parent\n"; // object
        $parent->rétablit[$this->id] = 'A2S';
        return;
      }
      default: return;
    }
  }
  
  // Je construis l'expr. fonctionelle topologique de la version avant en fonction des versions après
  // Par covention, j'utilise comme litéral l'id de la version
  // sauf quand cette version est valide actuellement où j'utilise l'id Rpicom
  function construitExprFonc(Rpicoms $rpicoms) {
    if ($this->rétablit) {
      //echo "this=$this\n";
      $vapres = $rpicoms->{$this->id}->{$this->end};
      //echo 'vapres='; print_r($vapres);
      $exprFonc = ($vapres->end == 'now') ? $this->id : "$this->id@$this->end"; // version après
      foreach ($this->rétablit as $erid => $code) {
        if (in_array($code, ['A2S','D2S','F2S'])) {
          $vapres = $rpicoms->$erid->{$this->end};
          $exprFonc = FunExp::union($exprFonc, ($vapres->end == 'now') ? "$erid" : "$erid@$this->end");
        }
      }
      $this->exprFonc = $exprFonc->__toString();
      FunExp::set("$this->id@$this->start", $exprFonc);
      echo "exprFonc: $this->id@$this->start = $exprFonc\n";
      //FunExp::show();
      //echo Yaml::dump([$this->id => [ "$this->start/$this->end" => $this->asArray()]], 4, 2);
      //throw new Exception("A faire construitExprFonc");
    }
  }
  
  // retourne le nom du dataset le plus récent géolocalisant cet objet ou '' s'il n'y en a pas
  function bestDataset(IndGeoJFile $igeojfile): string {
    if ($this->type == 'COMS')
      $validDatasets = Datasets::validBetween($this->start, $this->end); // les datasets pertinents du point de vue date
    elseif ($this->end == 'now') // un seul référentiel des COMA/COMD
      $validDatasets = ['Ae2020CogR'];
    else
      $validDatasets = [];
    if ($validDatasets) {
      $indexDatasets = $igeojfile->datasets($this->id); // les datasets dans lesquels l'id est défini
      if ($possibleDatasets = array_intersect($validDatasets, $indexDatasets)) {
        //echo Yaml::dump(['$possibleDatasets'=> $possibleDatasets]);
        return array_values($possibleDatasets)[0];
      }
    }
    return '';
  }
  
  // Recherche d'un objet géographique identique géolocalisable
  function substitut(IndGeoJFile $igeojfile, Rpicoms $rpicoms) {
    if (!($feature = Feature::get("$this->id@$this->start"))) {
      echo "$this->id@$this->start absent du graphe d'inclusion\n";
      return [];
    }
    foreach (array_reverse($feature->ids()) as $vid) { // les vid equals $this
      if (!($eq = $rpicoms->$vid))
        throw new Exception("Erreur d'utilisation de rpicoms->$vid");
      if (!in_array(get_class($eq), ['Version2', 'VComDP']))
        throw new Exception("Erreur d'appel de possibleDataset() sur $vid qui est ".get_class($eq));
      if ($bestDataset = $eq->bestDataset($igeojfile))
        return [$bestDataset, $vid];
    }
    return [];
  }
  
  // recherche d'un majorant de $this
  // retourne le dataset dans lequel je trouve un majorant de $this ainsi que le vid majorant défini dans ce dataset
  // retourne [datasetId, overEstimVid] avec overEstimVid défini dans datasetId
  function overEstim(IndGeoJFile $igeojfile, Rpicoms $rpicoms, int $recursiveCounter=0): array {
    //echo str_repeat("* ", $recursiveCounter),"$this->id@$this->start ->overEstim()\n";
    if (!($n = Feature::get("$this->id@$this->start"))) {
      echo "$this->id@$this->start absent du graphe d'inclusion\n";
      return [];
    }
    foreach ($n->ids() as $vid) { // les vid equals $this
      if (!($eq = $rpicoms->$vid))
        throw new Exception("Erreur d'utilisation de $vid");
      if (get_class($eq) <> 'Version2')
        throw new Exception("Erreur d'appel de possibleDataset() sur $vid qui n'est pas Version2");
      if ($bestDataset = $eq->bestDataset($igeojfile))
        return [$possibleDataset, $vid];
    }
    if (!($containings = $n->containing())) { // si aucun objet ne contient $this
      //echo str_repeat("* ", $recursiveCounter), "aucun objet ne contient $this->id@$this->start\n";
      //if ($recursiveCounter==0) die();
      return [];
    }
    foreach ($containings as $containing) // les noeuds contenant $this
      foreach ($containing->ids() as $containingVid) // les vid contenant $this
        if ($bestDataset = $rpicoms->$containingVid->bestDataset($igeojfile))
          return [$bestDataset, $containingVid];
    if ($recursiveCounter >= 10)
      throw new Exception("Erreur de boucle dans Version2::overEstim()");
    foreach ($containings as $containing) // les noeuds contenant $this
      foreach ($containing->ids() as $containingVid) // les vid contenant $this
        if ($overEstim = $rpicoms->$containingVid->overEstim($igeojfile, $rpicoms, $recursiveCounter+1))
          return $overEstim;
    return [];
  }

  // simule une géoloc et enregistre le JD dans $this->geolocDataset
  function testGeoLoc(IndGeoJFile $igeojfile, Rpicoms $rpicoms, array &$stats): void {
    //echo "$this->id@$this->start ->testGeoLoc()\n";
    try {
      if ($this->end == 'now') {
        $this->geolocDataset = ($this->type == 'COMS') ? 'Ae2020Cog' : 'Ae2020CogR';
        $stats['exactNow']++; // géoloc exact avec le réf. IGN le plus récent
      }
      /* Il est préférable d'utiliser systématiquement substitut() pour obtenir quand c'est possible l'AeCog récent
        elseif ($bestDataset = $this->bestDataset($igeojfile)) {
        $this->geolocDataset = $bestDataset;
        $stats['exactBestDs']++; // géoloc exact dans un précédent réf. IGN 
      }*/
      elseif ($substitut = $this->substitut($igeojfile, $rpicoms)) {
        $this->geolocDataset = $substitut;
        $stats['substitut']++;
      }
      elseif ($overEstim = $this->overEstim($igeojfile, $rpicoms)) {
        $this->geolocDataset = $overEstim;
        $stats['majoré']++;
      }
      else {
        $this->geolocDataset = '<b>AUCUN</b>';
        $stats['nonGéoloc']++;
      }
    }
    catch (Exception $e) {
      echo '<b>',$e->getMessage(),"</b>\n";
      $this->geolocDataset = '<b>'.$e->getMessage().'</b>';
      echo Yaml::dump([$this->id => $rpicoms->{$this->id}->asArray()], 4, 2);
      throw new Exception($e->getMessage());
    }
  }
  
  function geoloc(Rpicoms $rpicoms, IndGeoJFile $igeojfile, GeoJFileW $geojfilew, array &$nbs): void {
    $ref = null; // l'objet provenant du référentiel
    if ($this->end == 'now') {
      $source = ($this->type == 'COMS') ? 'Ae2020Cog' : 'Ae2020CogR';
      $gtype = '';
      $id = $this->id;
      if (!($ref = $igeojfile->feature($id, $source))) {
        $ref = $igeojfile->feature($id, 'Ae2019Cog'); // traitement des 4 objets absents du Cog2920
      }
    }
    elseif ($bestDataset = $this->bestDataset($igeojfile)) {
      $source = $bestDataset;
      $gtype = '';
      $id = $this->id;
    }
    elseif ($substitut = $this->substitut($igeojfile, $rpicoms)) {
      $source = $substitut[0];
      $gtype = '';
      $id = substr($substitut[1], 0, strpos($substitut[1], '@'));
    }
    elseif ($overEstim = $this->overEstim($igeojfile, $rpicoms)) {
      $source = $overEstim[0];
      $gtype = 'MAJ';
      $id = substr($overEstim[1], 0, strpos($overEstim[1], '@'));
    }
    else {
      $nbs['erreurs']++;
      return;
    }
    $properties = [
      'vid'=> "$this->id@$this->start",
      'id'=> $this->id,
      'name'=> $this->name,
      'type'=> $this->type,
      'parent'=> $this->parent,
      'start'=> $this->start,
      'startEvt'=> $this->startEvt ? $this->startEvt->str() : '',
      'end'=> ($this->end == 'now') ? '9999' : $this->end,
      'endEvt'=> $this->endEvt ? $this->endEvt->str() : '',
      'gtype'=> $gtype,
      'source'=> $source,
    ];
    if ($ref || ($ref = $igeojfile->feature($id, $source))) {
      //echo Yaml::dump([$properties]);
      $geojfilew->write([
        'type'=> 'Feature',
        'properties'=> $properties,
        'geometry'=> $ref['geometry'],
      ]);
      $nbs['records']++;
    }
    else {
      echo Yaml::dump(['erreur'=> $properties]);
     $nbs['erreurs']++;
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
  protected $parent; // string - id du parent

  function __construct(string $parent, string $end, ?Evt2 $endEvt, array $yaml) {
    $this->id = $parent.'D';
    $this->end = $end;
    $this->endEvt = $endEvt;
    $this->name = $yaml['name'];
    $this->parent = $parent;
  }
  
  function setStart(string $start, Evt2 $startEvt) {
    $this->start = $start;
    $this->startEvt = $startEvt;
  }
  
  function __toString() { return "$this->id@$this->start"; }
  
  function asArray(): array {
    $array = [];
    //if ($this->endEvt) $array['endEvt'] = $this->endEvt->value();
    //if ($this->end) $array['end'] = $this->end;
    $array['name'] = $this->name;
    //if ($this->startEvt) $array['startEvt'] = $this->startEvt->value();
    //if ($this->start) $array['start'] = $this->start;
    return $array;
  }

  // retourne le nom du dataset le plus récent géolocalisant cet objet ou '' s'il n'y en a pas
  function bestDataset(IndGeoJFile $igeojfile): string {
    $indexDatasets = $igeojfile->datasets($this->parent); // les datasets dans lesquels l'id est défini
    if (in_array('Ae2020CogR', $indexDatasets))
      return 'Ae2020CogR';
    else
      return '';
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
  
  function detecteErreurs(Rpicoms $rpicoms): void {} // aucun traitement
  
  function buildInclusionGraph(Rpicoms $rpicoms) {
    try {
      if ($this->endEvt) {
        switch ($this->endEvt->type()) {
          case 'entreDansRpicom': return; // ne rien à faire
        
          case 'arriveDansLeDépartementAvecLeCode': return; // ne rien à faire, géré par quitteLeDépartementEtPrendLeCode
        
          case 'rétablieCommeSimpleDe': {
            // CS  correspond à "$this->id@$this->end"
            //$idRat = $this->endEvt->cible(); // no INSEE de la c. de rattachement
            // CR' correspond à "$idRat@$this->end"
            //$previousVersionDate = $rpicoms->$idRat->previousVersionDate($this->end);
            // CR correspond à "$idRat@$previousVersionDate"
            //Feature::goc("$idRat@$this->end")->within("$idRat@$previousVersionDate");
            //Feature::goc("$this->id@$this->end")->within("$idRat@$previousVersionDate");
            if ($this->startEvt) { // si évt antérieur de supression, la version avant supression et après rétabliss. sont identiques
              if ($previousVersionDate = $rpicoms->{$this->id}->previousVersionDate($this->start))
                Feature::goc("$this->id@$this->end")->equals("$this->id@$previousVersionDate");
            }
            return;
          }
          case 'rétablieCommeAssociéeDe': {
            //$idRat = $this->endEvt->cible();
            //$previousVersionDate = $rpicoms->$idRat->previousVersionDate($this->end);
            //Feature::goc("$this->id@$this->end")->within("$idRat@$previousVersionDate");
            //Feature::goc("$idRat@$this->end")->equals("$idRat@$previousVersionDate"); //<----
            if ($this->startEvt) { // si évt antérieur de supression, la version avant supression et après rétabliss. sont identiques
              if ($previousVersionDate = $rpicoms->{$this->id}->previousVersionDate($this->start))
              //echo "$this->id@$this->end equals $this->id@$previousVersionDate\n";
                Feature::goc("$this->id@$this->end")->equals("$this->id@$previousVersionDate");
            }
            return;
          }
        
          case 'crééeParFusionSimpleDe': {
            $vEnd = Feature::goc("$this->id@$this->end");
            foreach ($this->endEvt->cible() as $cible) {
              if ('' !== ($start = $rpicoms->$cible->startOfVersionEnding($this->end))) {
                //echo "$this->id@$this->end contains $cible@$start\n";
                Feature::goc("$this->id@$this->end")->contains("$cible@$start");
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
    catch(Exception $e) {
      echo $e->getMessage(),"\n",$e->getTraceAsString(),"\n";
      echo Yaml::dump([$this->id => $rpicoms->{$this->id}->asArray(), $this->start => $this->asArray()], 5, 2);
      throw new Exception($e->getMessage());
    }
  }
  
  function detailleRetablissement(Rpicoms $rpicoms) {
    // Détaille les évts de rétablissements côté c. de rattachement afin de pouvoir construire ensuite l'opération 
    switch ($this->endEvt->type()) {
      case 'rétablieCommeSimpleDe': {
        $idRat = $this->endEvt->cible();
        $crat = $rpicoms->{"$idRat@/$this->end"};
        //echo "ajout rétablit pour crat=$crat\n"; // object
        $crat->ajoutRetablit($this->id, 'F2S');
        return;
      }
      case 'rétablieCommeAssociéeDe': {
        $idRat = $this->endEvt->cible();
        $crat = $rpicoms->{"$idRat@/$this->end"};
        //echo "ajout rétablit pour crat=$crat\n"; // object
        $crat->ajoutRetablit($this->id, 'F2A');
        return;
      }
      default: return;
    }
  }
  
  function construitExprFonc(Rpicoms $rpicoms) {} // aucun traitement
    
  function testGeoLoc(IndGeoJFile $igeojfile, Rpicoms $rpicoms, array &$stats): void { $stats['notAFeature']++; } // pas applicable

  function geoloc(Rpicoms $rpicoms, IndGeoJFile $igeojfile, GeoJFileW $geojfilew, array &$nbs): void {}
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


require_once __DIR__.'/base.inc.php';
require_once __DIR__.'/rpimap/igeojfile.inc.php';
require_once __DIR__.'/rpimap/datasets.inc.php';

if (php_sapi_name() <> 'cli')
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>testGeoloc</title></head><body><pre>\n";
$rpicomBase = new Base(__DIR__.'/rpicom', new Criteria(['not']));
//$rpicomBase = new Base(__DIR__.'/rpicomtest', new Criteria(['not']));
$rpicoms = new Rpicoms($rpicomBase->contents());

if (0) { // Erreur d'incohérence corrigée dans la création de Rpicoms 
  $rpicoms->detecteErreurs();
  die();
}

$rpicoms->buildInclusionGraph();
$igeojfile = new IndGeoJFile(__DIR__.'/data/aegeofla/index.igf');
$rpicoms->testGeoloc($igeojfile);
//print_r($rpicoms);
$rpicoms->dump(4, 2);
