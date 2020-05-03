<?php
/*PhpDoc:
name: rpicom2.inc.php
title: rpicom2.inc.php - structuration des Rpicom en classes
doc: |
  buildInclusionGraph() en cours d'écriture
  defDataset() à adapter pour utiliser le graphe
journal: |
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
    foreach ($this->rpicoms as $rpicom)
      $rpicom->setChildren($this);
    foreach ($this->rpicoms as $rpicom)
      $rpicom->buildInclusionGraph($this);
    echo count($this->rpicoms)," codes INSEE dans Rpicoms\n";
    $nbreFeatures = 0;
    foreach ($this->rpicoms as $rpicom)
      $nbreFeatures += $rpicom->nbreFeatures();
    echo "$nbreFeatures Features\n";
    Node::showStats();
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
  
  function mostRecent() { return array_values($this->versions)[0]; }
  
  // affichage du dernier nom barré s'il n'est plus valide
  function __toString() {
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
  
  function nbreFeatures(): int {
    $nbreFeatures = 0;
    foreach ($this->versions as $dv => $version)
      if (get_class($version) == 'Version2')
        $nbreFeatures++;
    return $nbreFeatures;
  }
  
  function setChildren(Rpicoms $rpicoms) {
    foreach ($this->versions as $dv => $version)
      $version->setChildren($rpicoms);
  }
  
  function buildInclusionGraph(Rpicoms $rpicoms) {
    foreach ($this->versions as $dv => $version)
      $version->buildInclusionGraph($rpicoms);
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
  
  // dans le cas d'un array la valeur contenue, sinon ''
  function cible(): string { return is_array($this->evt) ? array_values($this->evt)[0] : ''; }
    
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
  protected $id; // string - id du rpicom
  protected $start; // string - date de début ou '' si valide depuis le début du référentiel
  protected $startEvt; // evt de début ou null si valide depuis le début du référentiel
  protected $end; // string - date de fin ou 'now' si version courante
  protected $endEvt; // evt de fin ou null si version courante
  protected $name; // string
  protected $type; // string - {'COMA'|'COMD'|'ARM'|'COMS'}
  protected $parent; // string
  protected $children; // [ string ]
  protected $commeDéléguée; // VComDP ou null

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
  
  // complète avec la date et l'evt de début
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
    if ($this->startEvt) $array['startEvt'] = $this->startEvt->value();
    //if ($this->start) $array['start'] = $this->start;
    return $array;
  }

  function setChildren(Rpicoms $rpicoms) {
    if ($this->parent) {
      $parentId = $this->parent.'@'.$this->start;
      $parent = $rpicoms->$parentId;
      $parent->children[] = $this->id;
    }
  }
  
  function buildInclusionGraph(Rpicoms $rpicoms) {
    if ($this->endEvt) {
      switch ($this->endEvt->type()) {
        // ne change pas la géométrie
        case 'reçoitUnePartieDe': // simplification
        case 'seDissoutDans': // simplification
        case 'contribueA': // simplification
        
        case 'quitteLeDépartementEtPrendLeCode':
        case 'resteAssociéeA':
        case 'resteDéléguéeDe':
        case 'changedAssociéeEnDéléguéeDe':
        case 'Absorbe certaines de ses c. rattachées ou certaines de ses c. associées deviennent déléguées':
        case 'changeDeNom': {
          $vn = new Node("$this->id@$this->start");
          $vn->equals("$this->id@$this->end");
          return;
        }
        case 'seFondDans':
        case 'fusionneDans': {
          $vn = new Node("$this->id@$this->start");
          $vn->within($this->endEvt->cible().'@'.$this->end); // la fusionnée est dans la fusionnante
          return;
        }
        case 'devientDéléguéeDe':
        case 'sAssocieA': {
          $vn = new Node("$this->id@$this->start");
          $vn->equals("$this->id@$this->end"); // une association ne change pas la géométrie de l'associée
          $vn->within($this->endEvt->cible().'@'.$this->end); // l'associée est dans l'associante
          return;
        }
        case 'seCréeEnComNouvelle': {
          $vn = new Node("$this->id@$this->start");
          $vn->within("$this->id@$this->end"); // l'ancienne commune est dans la nouvelle
          return;
        }
        case 'seCréeEnComNouvAvecDélPropre': {
          $vn = new Node("$this->id@$this->start");
          $vn->within("$this->id@$this->end"); // l'ancienne commune est dans la nouvelle
          $vn->equals($this->id."D@$this->end"); // l'ancienne commune equals la commune déléguée propre
          return;
        }
        case 'PrendAssocOuAbsorbe': {
          $vn = new Node("$this->id@$this->start");
          $vn->within("$this->id@$this->end"); // l'ancienne commune est dans la nouvelle
          return;
        }
        case 'Commune déléguée rétablie comme commune simple':
        case 'Commune associée rétablie comme commune simple': {
          $vn = new Node("$this->id@$this->start");
          $vn->equals("$this->id@$this->end");
          return;
        }
        case 'sortDuRpicom': {
          return;
        }
        case 'changeDeRattachementPour':
        case 'perdRattachementPour':
        case 'Commune rattachée devient commune de rattachement':
        case 'rétablitCommunesRattachéesOuFusionnées': {
          // A VOIR
          return;
        }
        default: {
          echo Yaml::dump(Node::allAsArray());
          echo "<b>A faire buildInclusionGraph pour type=".$this->endEvt->type()." :</b>\n";
          echo Yaml::dump(['$id'=> $this->id, '$start'=> $this->start, 'rpicom'=> $rpicoms->{$this->id}->asArray()], 3, 2);
          throw new Exception("A faire buildInclusionGraph pour type=".$this->endEvt->type());
        }
      }
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
  protected $start; // string - date de destruction précédente ou ''
  protected $startEvt; // evt de destruction ou null
  protected $end; // string - date de création ou de fin d'interruption
  protected $endEvt; // evt de création ou de fin d'interruption

  function __construct(string $id, string $end, array $version) {
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

  function setChildren(Rpicoms $rpicoms) {}
  function buildInclusionGraph(Rpicoms $rpicoms) {}
};

