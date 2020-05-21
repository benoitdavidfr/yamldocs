<?php
/*PhpDoc:
name: fuslim.php
title: fusion des limites des communes simples avec celles des entités rattachées
doc: |
  L'objectif est de construire une couche d'objets polygones ou multi-polygones correspondant
    - 1) aux communes simples n'ayant pas d'entité rattachée
    - 2) aux entités rattachées consttuant à un pavage de communes simples
    - 3) dans le cas où les entités rattachées à une commune simple n'en constituent pas un pavage
      - aux entités rattachées est ajoutée une pseudo-entité rattachée qui correspond à l'espace restant
  L'exemple typique est celui des communes associées où l'union des c. associées à une c. simple ne couvre pas à la totalité
  du territoire de la comune simple et où il est pertinent de définir un territoire complémentaire
journal: |
  21/5/2020:
    - 18 erreurs détectées d'impossibilité de déterminer le polygone pertinent
      - pour 48105 dont les polygones de COMS et d'ER ne correspondent pas, intégration dans le code d'une correction de l'erreur
      - pour les autres une 2nde phase est mise en oeuvre pour effectuer les corrections
        - avec qqes cas de corrections automatiques
        - et qqs cas de corrections manuelles
*/
require_once __DIR__.'/../geojfile.inc.php';
require_once __DIR__.'/../geojfilew.inc.php';
require_once __DIR__.'/../../../../geovect/gegeom/gegeom.inc.php';
require_once __DIR__.'/../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '4G');

function lpos2lseg(array $lpos): array { // transforme une LPos en liste de segments
  $lseg = [];
  foreach ($lpos as $pos) {
    if (isset($posprec))
      $lseg[] = new gegeom\Segment($posprec, $pos);
    $posprec = $pos;
  }
  return $lseg;
}

// teste la superposition partielle ou totale entre 2 lignes brisées issues d'un même graphe topologique
// renvoie l'intervalle des no de segments de la première ligne qui correspondent à des segments de la seconde
// sous la forme d'un array de 2 valeurs ou [] si aucun segment en commun
function lPosIntersects(array $lpos1, array $lpos2): array {
  $lseg1 = lpos2lseg($lpos1);
  $lseg2 = lpos2lseg($lpos2);
  $min = -1;
  $max = -1;
  foreach ($lseg1 as $no => $seg) {
    if (in_array($seg, $lseg2)) {
      if ($min == -1)
        $min = $no;
      $max = $no;
    }
  }
  if ($min == -1)
    return [];
  else
    return [$min, $max];
}

if (0) { // Test unitaire de la fonction lPosIntersects() 
  $lpos1 = [[0,0],[0,1],[0,2],[0,3],[0,4]];
  foreach ([
    [[-1,0],[0,0]],
    [[-1,0],[0,0],[0,1]],
    [[-1,0],[0,0],[0,1],[0,2]],
    [[0,2],[0,3]],
    [[0,2],[0,3],[0,4]],
    [[0,2],[0,3],[0,4],[0,5]],
  ] as $lpos2) {
    echo Yaml::dump([['$lpos1'=>$lpos1, '$lpos2'=>$lpos2, 'lPosIntersects'=> lPosIntersects($lpos1, $lpos2)]], 2);
  }
  die("Fin tests ligne ".__LINE__."\n");
}

// ens. d'intervalles de segments, permet de détecter les intervalles de num. de segments non couverts par une limite
class Intervals {
  protected $nbsegs; // nbre global de segments
  protected $subs = []; // [min => max] - liste des intervalles couverts

  function __construct(int $nbsegs) { $this->nbsegs = $nbsegs; }
  
  function add(int $min, int $max) { // ajout d'un intervalle de segments
    if (isset($this->subs[$min]))
      die("Ecrasement pour $min");
    $this->subs[$min] = $max;
  }
  
  function asArray(): array { // affichage de l'objet
    ksort($this->subs);
    $subs = [];
    foreach ($this->subs as $min => $max)
      $subs[] = "$min - $max";
    return [
      'nbsegs'=> $this->nbsegs,
      'subs'=> $subs,
    ];
  }
  
  function remaining(): array { // renvoit [] si tous les segments sont couverts, sinon la liste des segments restants
    $remaining = [];
    ksort($this->subs);
    $min = 0;
    foreach ($this->subs as $smin => $smax) {
      if ($smin <> $min) {
        $remaining[$min] = $smin - 1;
      }
      $min = $smax + 1;
    }
    if ($min <> $this->nbsegs)
      $remaining[$min] = $this->nbsegs - 1;
    return $remaining;
  }

  static function test(): void {
    $ints = new Intervals(100);
    $ints->add(1, 9);
    $ints->add(10, 19);
    //$ints->add(20, 29);
    //$ints->add(30, 99);
    echo Yaml::dump(['$ints'=> $ints->asArray()]);
    echo Yaml::dump(['remaining'=> $ints->remaining()]);
    die("Fin tests ligne ".__LINE__."\n");
  }
};

if (0) Intervals::test(); // Test unitaire de la classe Intervals

// construction des faces pour retouver plus facilement leurs brins
class Face {
  static $all=[]; // [ id => Face ]
  protected $id;
  protected $bladeNums=[]; // [ bnum ] - liste des brins limitant la face
  
  static function getOrCreate(string $id): Face { return Face::$all[$id] ?? Face::$all[$id] = new Face($id); }
  
  function __construct(string $id) { $this->id = $id; }
  function id(): string { return $this->id; }
  function addBlade(int $bladeNum): void { $this->bladeNums[] = $bladeNum; }
  function bladeNums() { return $this->bladeNums; }
  
  static function bladeNumsForCInsee(string $rightCInsee, string $leftCInsee): array {
    $bnums = [];
    foreach (Face::$all as $id => $face) {
      if (substr($id, 0, 5) == $rightCInsee) {
        foreach ($face->bladeNums() as $bnum) {
          $blade = Blade::get($bnum);
          if (($leftCInsee == '*') || (substr($blade->left()->id(), 0, 5) == $leftCInsee)) {
            $bnums[] = $bnum;
          }
        }
      }
    }
    return $bnums;
  }
};

abstract class Blade {
  static function get(int $bnum) { return ($bnum > 0) ? Lim::$all[$bnum] : Lim::$all[-$bnum]->inv(); }
};

class Lim extends Blade {
  static $all=[]; // [num => Lim] - les limites de la carte des c. simples
  static $new=[]; // [num => Feature] - les nlles limites des entités rattachées
  protected $right; // Face de c. simple
  protected $left; // Face de c. simple
  protected $statut; // Statut initial comme limite entre communes simples
  protected $coords; // LPos
  protected $newCodes=[]; // [min => [max => ['right'=> right, 'left'=> left]]] - nvx codes provenant des limites ajoutées
  
  static function select(array $feature): bool {
    //$depts = ['14','50'];
    //$depts = ['48'];
    //$depts = ['28'];
    $depts = [];
    if (!$depts)
      return true;
    return (in_array(substr($feature['properties']['right'], 0, 2), $depts)
      || in_array(substr($feature['properties']['left'], 0, 2), $depts));
  }
  
  static function add(array $feature): void { // ajout des limites de licomfr
    if (!self::select($feature)) return;
    //echo Yaml::dump(['add'=> $feature]);
    $numlim = count(self::$all) + 1;
    $right = Face::getOrCreate($feature['properties']['right']);
    $left = Face::getOrCreate($feature['properties']['left']);
    self::$all[$numlim] = new self($right, $left, $feature['properties']['statut'], $feature['geometry']['coordinates']);
    $right->addBlade($numlim);
    $left->addBlade(- $numlim);
  }
  
  function __construct(Face $right, Face $left, string $statut, array $coords) {
    $this->right = $right;
    $this->left = $left;
    $this->statut = $statut;
    $this->coords = $coords;
  }
  function right(): Face { return $this->right; }
  function left(): Face { return $this->left; }
  function coords(): array { return $this->coords; }
  function newCodes(): array { return $this->newCodes; }
  
  function inv() { return new Inv($this); }
  
  function asArray(): array {
    return [
      'right'=> $this->right->id(),
      'left'=> $this->left->id(),
      'coords'=> $this->coords,
      'newCodes'=> $this->newCodes,
    ];
  }
  
  // ajoute à la carte une limite d'entité répartie
  // la carte Face / Blade correspond aux c. simples
  // Cette carte est modifiée de la manière suivante:
  //  - je stocke sur chaque limite les nvx codes à droite et à gauche
  static function fusion(array $feature): void {
    if (!self::select($feature)) return;
    if ($feature['properties']['right'] == '48105/0') { // Correction du polygone 48105/0 des entités rattachées
      echo "Correction du polygone 48105/0 des entités rattachées\n";
      $feature['geometry']['coordinates'] = Blade::get(Face::$all['48105/0']->bladeNums()[0])->coords();
    }
    $segints = new Intervals(count($feature['geometry']['coordinates']) - 1); // intervalles de no de segments
    $rightParent = $feature['properties']['rightParent'] ?? '';
    $leftParent = $feature['properties']['leftParent'] ?? '';
    if ($rightParent == $leftParent) { // cas 1 - même parent des 2 côtés, c'est une limite interne
      self::$new[] = [
        'type'=> 'Feature',
        'properties' => [
          'right'=> $feature['properties']['right'],
          'left'=> $feature['properties']['left'],
          'statut'=> 'I2', // limite entre 2 entités rattachées interne à une commune simple
          'fuslim'=> 'cas 1 - mêmes parents des 2 côtés',
        ],
        'geometry'=> $feature['geometry'],
      ];
      return;
    }
    elseif (!$leftParent) { // cas 3 - pas de parent à gauche
      //echo "cas3: pas de parent à gauche\n";
      //echo Yaml::dump(['fusion' => $feature]);
      $bnums = Face::bladeNumsForCInsee($feature['properties']['rightParent'], '*');
      //echo Yaml::dump(['$bnums' => $bnums]);
      foreach ($bnums as $bnum) {
        if ($intersects = lPosIntersects($feature['geometry']['coordinates'], Blade::get($bnum)->coords())) {
          $segints->add($intersects[0], $intersects[1]);
          //echo Yaml::dump(['segints'=> $segints->asArray()]);
          Blade::get($bnum)->intersects($feature);
        }
      }
      if ($segints->remaining()) {
        //echo "cas3: pas de parent à gauche, reste\n";
        //echo Yaml::dump(['fusion' => $feature]);
        //echo Yaml::dump(['segints'=> $segints->asArray()]);
        //echo Yaml::dump(['remains-segints'=> $segints->remaining()]);
        //array_slice ( array $array , int $offset [, int $length = NULL [, bool $preserve_keys = FALSE ]] ) : array
        foreach ($segints->remaining() as $min => $max) {
          $rightParent = $feature['properties']['rightParent'];
          $right = $feature['properties']['right'];
          if (isset(Face::$all["$rightParent/u"]))
            $rightParent = "$rightParent/u";
          else
            //throw new Exception("Impossible de connaitre le no de polygone du parent $rightParent");
            echo("Impossible de déterminer le no de polygone du parent $rightParent pour la limite de $right\n");
          $newFeat = [
            'type'=> 'Feature',
            'properties'=> [
              'right'=> $right,
              'left'=> $rightParent,
              'statut'=> 'I1', // limite d'1 entité rattachée, interne à une commune simple
              'fuslim'=> "3 - limerat externe, reste $min $max",
              //'oldProperties'=> $feature['properties'],
            ],
            'geometry'=> [
              'type'=> 'LineString',
              'coordinates'=> array_slice($feature['geometry']['coordinates'], $min, $max - $min + 2),
            ],
          ];
          self::$new[] = $newFeat;
          //echo Yaml::dump(['new' => $newFeat]);
        }
      }
      else {
        //echo "-> fusion ok\n";
      }
    }
    elseif (!$rightParent) { // cas 3 bis - pas de parent à droite
      echo "cas3bis: pas de parent à droite\n";
      echo Yaml::dump(['fusion' => $feature]);
      throw new Exception("A faire");
    }
    else {
      //echo "cas2: parents droite et gauche distincts\n";
      //echo Yaml::dump(['fusion' => $feature]);
      // récupération des brins potentiels
      $bnums = Face::bladeNumsForCInsee($rightParent, $leftParent);
      //echo Yaml::dump(['$bnums' => $bnums]);
      foreach ($bnums as $bnum) {
        if ($intersects = lPosIntersects($feature['geometry']['coordinates'], Blade::get($bnum)->coords())) {
          $segints->add($intersects[0], $intersects[1]);
          //echo Yaml::dump(['segints'=> $segints->asArray()]);
          Blade::get($bnum)->intersects($feature);
        }
      }
      if ($ints = $segints->remaining()) {
        echo Yaml::dump(['remains-segints'=> $segints->asArray()]);
        throw new Exception("Cas non traité ligne ".__LINE__);
      }
      else {
        //echo "-> fusion ok\n";
      }
    }
  }
  
  static function combineFaceId(string $parentId, string $childId): string { // combinaison des codes avant pour définir le nouveau
    return $childId ? $childId : $parentId;
  }
  
  function intersects(array $feature): void { // enregistre les nouvelles valeurs à droite et à gauche
    $intesects = lPosIntersects($this->coords(), $feature['geometry']['coordinates']);
    $this->newCodes[$intesects[0]][$intesects[1]] = [
      'right'=> self::combineFaceId($this->right->id(), $feature['properties']['right']),
      'left' => self::combineFaceId($this->left->id(),  $feature['properties']['left']),
    ];
  }
  
  function writeLim(GeoJFileW $newlimGeoJFile): void { // enregistre les anciennes limites
    if ($this->newCodes) { // découpe l'ancienne limite selon newCodes et écrit les morceaux
      $ints = new Intervals(count($this->coords) - 1);
      foreach ($this->newCodes as $min => $newCodesRest) {
        foreach ($newCodesRest as $max => $newCode) {
          $newlimGeoJFile->write([
            'type'=> 'Feature',
            'properties'=> [
              'right'=> self::combineFaceId($this->right->id(), $newCode['right']),
              'left'=> self::combineFaceId($this->left->id(), $newCode['left']),
              'statut'=> $this->statut,
              'fuslim'=> "Anc. lim. découpée $min $max",
            ],
            'geometry'=> [
              'type'=> 'LineString',
              'coordinates'=> array_slice($this->coords, $min, $max - $min + 2),
            ],
          ]);
          $ints->add($min, $max);
        }
      }
      if ($remaining = $ints->remaining()) { 
        //echo Yaml::dump(['$ints'=> $ints->asArray(), '$remaining'=> $remaining]);
        foreach ($remaining as $min => $max) {
          $newlimGeoJFile->write([
            'type'=> 'Feature',
            'properties'=> [
              'right'=> $this->right->id(),
              'left'=> $this->left->id(),
              'statut'=> $this->statut,
              'fuslim'=> "Anc. lim. reste $min $max",
            ],
            'geometry'=> [
              'type'=> 'LineString',
              'coordinates'=> array_slice($this->coords, $min, $max - $min + 2),
            ],
          ]);
        }
      }
    }
    else { // sinon écrit la limite telle quelle
      $newlimGeoJFile->write([
        'type'=> 'Feature',
        'properties'=> [
          'right'=> $this->right->id(),
          'left'=> $this->left->id(),
          'statut'=> $this->statut,
          'fuslim'=> "Ancienne limite entre COMS non modifiée",
        ],
        'geometry'=> [
          'type'=> 'LineString',
          'coordinates'=> $this->coords,
        ],
      ]);
    }
  }
  
  static function writeNewLim(GeoJFileW $newlimGeoJFile): void { // écrit les nouvelles limites
    foreach (self::$new as $num => $feature) {
      //echo Yaml::dump([$num => $lim]);
      $newlimGeoJFile->write($feature);
    }
  }
};

class Inv extends Blade {
  protected $inv; // Lim
  
  function __construct(Lim $lim) { $this->inv = $lim; }
  function right() { return $this->inv->left(); }
  function left() { return $this->inv->right(); }
  function coords(): array { return array_reverse($this->inv->coords()); }
  
  function intersects(array $feature): void { // enregistre les nouvelles valeurs à droite et à gauche
    $this->inv->intersects([
      'properties' => [
        'right'=> $feature['properties']['left'],
        'left'=> $feature['properties']['right'],
      ],
      'geometry'=> [
        'coordinates'=> array_reverse($feature['geometry']['coordinates']),
      ],
    ]);
  }
};

if (php_sapi_name()<>'cli') echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>fuslim</title></head><body><pre>\n";

$limcomPath = __DIR__.'/limcomfr.geojson';
$limeratPath = __DIR__.'/limerat.geojson';
$geojfile = new GeoJFile($limcomPath);
foreach ($geojfile->quickReadFeatures() as $feature) {
  Lim::add($feature);
}
//print_r(Face::$all);
$geojfile = new GeoJFile($limeratPath);
//if (0)
foreach ($geojfile->quickReadFeatures() as $feature) {
  Lim::fusion($feature);
}

if (0) {
  foreach (Lim::$all as $num => $lim) {
    if ($lim->newCodes())
      echo Yaml::dump([$num => $lim->asArray()]);
  }
}

$newlimGeoJFile = new GeoJFileW(__DIR__.'/tmp.geojson', 'limfus', []);
foreach (Lim::$all as $num => $lim) {
  $lim->writeLim($newlimGeoJFile);
}
Lim::writeNewLim($newlimGeoJFile);
$newlimGeoJFile->close();

/* Le traitement précédent génère une erreur sur certaines limites pour lesquelles il n'est pas possible de déterminer le polygone
** Une deuxième phase de traitement est donc réalisée pour effectuer cette détermination.
*/

$limPath = __DIR__.'/tmp.geojson';
$geojfile = new GeoJFile($limPath);
$newlimGeoJFile = new GeoJFileW(__DIR__.'/limfus.geojson', 'limfus', [
  'modified' => date(DATE_ATOM),
  'source' => [$limcomPath, $limeratPath],
  'description' => "Limites fusionnées des communes simples et des entités rattachées générées par fuslim.php"
    ." à partir du fichier $limcomPath et $limeratPath",
]);
$featuresInError = [];
$faceIds = []; // [ c. Insee => [faceId => 1]];
foreach ($geojfile->quickReadFeatures() as $feature) {
  if (strlen($feature['properties']['left']) == 5) {
    $featuresInError[] = $feature;
  }
  else {
    $newlimGeoJFile->write($feature);
    if ($feature['properties']['left'])
      $faceIds[substr($feature['properties']['left'], 0, 5)][$feature['properties']['left']] = 1;
  }
  $faceIds[substr($feature['properties']['right'], 0, 5)][$feature['properties']['right']] = 1;
}
//echo '$featuresInError='; print_r($featuresInError);
$corrections = [ // corrections "manuelles"
  '08362' => '08362/1',
  '42218' => '42218/0',
  '70447' => '70447/1',
];
foreach ($featuresInError as $feature) {
  $leftId = $feature['properties']['left'];
  if (count($faceIds[$leftId]) == 1) { // s'il n'y a qu'une seule possibilié c'est bon
    $leftId2 = array_keys($faceIds[$leftId])[0];
    echo "Correction de $leftId par $leftId2\n";
    $feature['properties']['left'] = $leftId2;
  }
  elseif (isset($corrections[$leftId])) { // sinon corrections "manuelles"
    $leftId2 = $corrections[$leftId];
    echo "Correction de $leftId en $leftId2 effectuée\n";
    $feature['properties']['left'] = $leftId2;
  }
  else {
    echo "Correction de $leftId impossible\n";
    print_r($faceIds[$leftId]);
  }
  $newlimGeoJFile->write($feature);
}
$newlimGeoJFile->close();
