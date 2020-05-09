<?php
/*PhpDoc:
name: topomap.php
title: génération du fichier des limites entre communes à partir de Ae2020Cog par construction d'un graphe d'adjacence
doc: |
  Algo:
    1) Lecture du fichier Ae2020Cog et construction des faces dans Face::$all stockées par département utilisé comme index spatial
    2) confrontation de chaque face avec les autres pour en déduire une éventuelle limite commune,
       stockage des limites dans DirectBlade
    3) confrontation de chaque face avec les limites ainsi générées pour en déduire les limites restantes qui sont celles de l'extérieur
  Contraintes:
    - algo en carré du nbre de faces
    - utilisation des rectangles englobants pour réduire la durée des tests d'adjacence
    - la géométrie des faces ne peut être gérée en mémoire
      - chaque face renvoie vers le fichier GeoJSON en entrée dans lequel je recherche la géométrie
      - optimisation pour ne pas réouvrir le fichier GeoJSON à chaque demande
  Stats:
    - 35100 faces
journal: |
  9/5/2020:
    - exécution lancée sur tte la France dans la nuit du 8 au 9/5
      - @08:41: 12280 / 35100 traités dans buildAllBlades en 454.1 min.
      - estimation 22h de durée, fin prévue à 8:41 + 454.1/12280 * (35100-12280) = 22:45
    - possibilités d'amélioration
      - les géométries des limites pourraient être écrites au fur et à mesure et pas à la fin
        afin notamment de libérer la mémoire correspondant au stockage 
        - pb la géométrie des limites est utilisée à la fin pour calculer les limites extérieures
      - les trous dans les communes ne sont pas gérés
      - il y a parfois 2 limites qd il pourrait y en avoir qu'une
      - la face extérieure pourrait être gérée comme null et non stockée dans Face:$all
      - afficher l'heure d'estimation de fin
      - je pourrais gérer un buffer des géométries des communes pour garder en mémoire celles fréquemment utilisées
  8/5/2020:
    - première version proto testée sur les dépts 21 et 29
*/
//ini_set('memory_limit', '2048M');
ini_set('memory_limit', '10G');

require_once __DIR__.'/../geojfile.inc.php';
require_once __DIR__.'/../geojfilew.inc.php';
require_once __DIR__.'/../../../../geovect/gegeom/gegeom.inc.php';
require_once __DIR__.'/../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$geojfilePath = __DIR__.'/../data/aegeofla/AE2020COG/FRA/COMMUNE_CARTO.geojson';
//$dept = '21';
//$dept = '29';
$dept = '';

// Permet d'identifier les intervalles non couverts
class Intervals {
  protected $min; // min global
  protected $max; // max global
  protected $subs = []; // [min => max] - liste des intervalles couverts
  
  function __construct(int $min=null, int $max=null) { $this->min = $min; $this->max = $max; }
  
  function add(int $min, int $max) {
    if (isset($this->subs[$min]))
      throw new Exception("Erreur sur [$min .. $max [ ");
    $this->subs[$min] = $max;
  }
  
  function dump(string $label) {
    ksort($this->subs);
    echo Yaml::dump([$label=> ['min'=>$this->min, 'int'=> $this->subs, 'max'=>$this->max]], 3, 2);
  }
  
  /*function std(): self {
    ksort($this->subs);
    // suppression des intervalles en début
    $min = $this->min;
    while (isset($this->subs[$min])) {
      $this->min = $this->subs[$min];
      unset($this->subs[$min]);
      $min = $this->min;
    }
    
    // fusion des intervalles consécutifs
    $precmin = null;
    foreach ($this->subs as $min => $max) {
      if (($precmin !== null) && ($this->subs[$precmin]==$min)) {
        $this->subs[$precmin] = $max;
        unset($this->subs[$min]);
      }
      else
        $precmin = $min;
    }
    //return $this;
    
    $this->dump('avant supp. fin');
    // suppression des intervalles en fin
    foreach (array_reverse(array_keys($this->subs)) as $min) {
      $max = $this->subs[$min];
      if ($max == $this->max) {
        echo "$min -> $max oui\n";
        $this->max = $min;
        unset($this->subs[$min]);
      }
      else
        echo "$min -> $max non\n";
    }
    return $this;
  }*/
  
  function NONremaining(): array { // [min => max]
    $this->std();
    if ($this->min == $this->max) return [];
    $remaining = [];
    $precmax = $this->min;
    foreach ($this->subs as $min => $max) {
      $remaining[$precmax] = $min;
      $precmax = $max;
    }
    $remaining[$precmax] = $this->max;
    return $remaining;
  }
  
  function remaining(): array { // [min => max]
    if (!$this->subs)
      return [$this->min => $this->max];
    ksort($this->subs);
    $remaining = []; // [min => max]
    $precmax = $this->min;
    foreach ($this->subs as $min => $max) {
      if ($min <> $precmax)
        $remaining[$precmax] = $min;
      $precmax = $max;
    }
    if ($precmax <> $this->max)
      $remaining[$precmax] = $this->max;
    return $remaining;
  }
};

if (0) { // test de la classe Intervals 
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>topomap</title></head><body><pre>\n";
  $int = new Intervals(0, 100);
  if (0) {
    $int->add(0, 5);
    $int->add(90, 100);
    $int->add(10, 20);
    $int->add(40, 50);
    //$int->add(20, 30);
    $int->add(30, 40);
    $int->add(5, 10);
  }
  if (1) {
    $int->add(0, 10);
  }
  $int->dump('$int');
  //$int->std()->dump('std');
  echo "remaining:\n";
  echo Yaml::dump($int->remaining());
  die();
}

abstract class Blade {};

class DirectBlade extends Blade {
  static protected $all=[]; // [ numBlade => Blade ]
  protected $num;
  protected $right; // Face
  protected $left; // Face
  protected $start; // no du premier point dans l'extérieur du polygone $right
  protected $end; // no du dernier point dans l'extérieur du polygone $right
  protected $coords; // [Pos]

  static function create(Face $rightFace, Face $leftFace, int $start, int $end, array $coords): int {
    $num = count(self::$all) + 1; // le premier numéro est 1
    self::$all[$num] = new self($num, $rightFace, $leftFace, $start, $end, $coords);
    return $num;
  }
  
  static function get(int $num): Blade {
    if ($num < 0)
      return new InvBlade(self::$all[-$num]);
    else
      return self::$all[$num];
  }
  
  static function dump(): void {
    foreach (self::$all as $num => $blade) {
      echo Yaml::dump([$num => $blade->asArray()]);
    }
  }
  
  static function writeGeoFile(string $path): void {
    $file = new GeoJFileW($path);
    foreach (self::$all as $blade) {
      $file->write($blade->asGeojson());
    }
    $file->close();
    echo "Fichier $path écrit\n";
  }
  
  function __construct(int $num, Face $right, Face $left, int $start, int $end, array $coords) {
    $this->num = $num;
    $this->right = $right;
    $this->left = $left;
    $this->start = $start;
    $this->end = $end;
    $this->coords = $coords;
  }
  
  function num(): int { return $this->num; }
  function rightId(): string { return $this->right->id(); }
  function leftId(): string { return $this->left->id(); }
  function coords(): array { return $this->coords; }
  
  function asArray(): array {
    return [
      'num'=> $this->num,
      'right'=> $this->right->asArray(),
      'left'=> $this->left->asArray(),
      'coords'=> $this->coords,
    ];
  }

  function asGeojson(): array {
    return [
      'type'=> 'Feature',
      'properties'=> [
        'num'=> $this->num,
        'right'=> $this->right->id(),
        'left'=> $this->left->id(),
      ],
      'geometry'=> [
        'type'=> 'LineString',
        'coordinates'=> $this->coords,
      ],
    ];
  }
};

class InvBlade extends Blade {
  protected $inv;
  
  function __construct(DirectBlade $dblade) { $this->inv = $dblade; }
  
  function num() { return - $this->inv->num(); }
  function rightId() { return $this->inv->leftId(); }
  function leftId() { return $this->inv->rightId(); }
  function __toString(): string { return $this->num().': {rightId: '.$this->rightId().', leftId: '.$this->leftId().'}'; }
  function coords(): array { return array_reverse($this->inv->coords()); }
};

class Face {
  static $geojfilePath; // chemin du fichier geojson des objets
  static $geojfile = null; // descripteur du fichier geojson des objets
  static protected $all=[]; // [dept => ['bbox'=> bbox, 'faces'=> [id => Face]]]
  protected $id;
  protected $bbox = null;
  //protected $coords;
  protected $ftell;
  protected $blades=[]; // liste des nums de Blade
  
  /*static function get(string $id): Face {
    return self::$all[$id] ?? [];
  }*/

  static function allFaces(): array {
    $all = [];
    foreach (self::$all as $dept => $facesOfDept) {
      $all = array_merge($all, $facesOfDept['faces']);
    }
    return $all;
  }
  
  static function select(gegeom\GBox $box) {
    $select = [];
    foreach (self::$all as $dept => $facesOfDept) {
      if (!isset($facesOfDept['bbox'])) continue;
      if ($box->intersects($facesOfDept['bbox'])) {
        foreach ($facesOfDept['faces'] as $face) {
          if ($box->intersects($face->bbox))
            $select[] = $face;
        }
      }
    }
    return $select;
  }
  
  static function dump() {
    $all = self::allFaces();
    ksort($all);
    foreach($all as $id => $face) {
      echo Yaml::dump([$id => $face->asArray()]);
    }
  }

  static function serialize(): string {
    return serialize([self::$geojfilePath, self::$all]);
  }
  
  static function unserialize(string $ser): void {
    list(self::$geojfilePath, self::$all) = unserialize($ser);
  }
  
  function __construct(string $id, array $coords=[], int $ftell=-1) {
    //echo "__construct($id, coords, $ftell)\n";
    $this->id = $id;
    //$this->coords = $coords;
    $this->ftell = $ftell;
    if ($coords) {
      $polygon = gegeom\Geometry::fromGeoJSON(['type'=>'Polygon', 'coordinates'=> $coords]);
      $this->bbox = $polygon->bbox();
    }
    //print_r($this->bbox);
    
    $dept = substr($this->id, 0, 2);
    self::$all[$dept]['faces'][$this->id] = $this;
    if ($this->bbox)
      self::$all[$dept]['bbox'] = !isset(self::$all[$dept]['bbox']) ? $this->bbox : $this->bbox->union(self::$all[$dept]['bbox']);
  }
  
  function id() { return $this->id; }
  
  function coords(): array {
    if (!self::$geojfile)
      self::$geojfile = new GeoJFile(self::$geojfilePath);
    $feature = self::$geojfile->quickReadOneFeature($this->ftell);
    //echo "id=$this->id\n";
    $nopol = substr($this->id, strpos($this->id, '/')+1);
    //echo "nopol=$nopol\n";
    if ($nopol == 'u')
      return $feature['geometry']['coordinates']; // cas Polygone
    else
      return $feature['geometry']['coordinates'][$nopol]; // cas MultiPolygone
  }
  
  function asArray() {
    return [
      'id'=> $this->id,
      //'nbCoords'=> $this->coords ? count($this->coords[0]) : 0,
      'ftell'=> $this->ftell,
      'blades'=> $this->blades,
    ];
  }
  
  static function buildAllBlades(float $debut) { // Fabrique les brins de chaque face
    $counter=0;
    $all = self::allFaces();
    $nbre = count($all);
    foreach ($all as $id => $face) {
      $face->buildBlades();
      if (++$counter % 10 == 0)
        printf("$counter / $nbre traités dans buildAllBlades en %.1f min.\n", (time()-$debut)/60);
      //if ($counter > 20) break;
    }
    echo "Fin de buildAllBlades\n";
  }

  function buildBlades() { // fabrique les brins de la face
    //echo "buildBlades@$this->id\n";
    $thisCoords = $this->coords()[0];
    foreach (self::select($this->bbox) as $id => $face) {
      if ($face->id >= $this->id) continue;
      //echo "$this->id touches? $face->id\n";
      foreach ($this->touches($thisCoords, $face->coords()[0]) as $touches) {
        //echo "$this->id touches $face->id, "; echo "touches= [$touches[0], $touches[1]]\n";
        //array_slice ( array $array , int $offset [, int $length = NULL [, bool $preserve_keys = FALSE ]] ) : array
        $bladeCoords = array_slice($thisCoords, $touches[0], $touches[1]-$touches[0]+1);
        $bladeNum = DirectBlade::create($this, $face, $touches[0], $touches[1], $bladeCoords);
        $this->blades[] = $bladeNum;
        $face->blades[] = - $bladeNum;
      }
    }
  }
  
  // retourne un array d'array de 2 indices correspondant aux positions de début et de fin des points constituant la limite partagée
  function touches(array $thisCoords, array $faceCoords): array {
    //echo "<b>$this->id ->touches</b>\n";
    $ltouches = []; // array d'array de 2 indices
    $touches = []; // array de 2 indices
    foreach ($thisCoords as $ipos => $pos) {
      $in = in_array($pos, $faceCoords);
      //echo $in ? "$ipos dans coords\n" : "$ipos hors coords\n";
      if ($in && !$touches) { // 1er point commun
         $touches = [$ipos, $ipos];
      }
      elseif ($in && $touches && ($ipos == $touches[1]+1)) { // pt commun suivant
        $touches[1] = $ipos;
      }
      elseif (!$in && $touches) { // fin de ligne commune
        if ($touches[1] <> $touches[0]) // on ne conserve pas les points isolés
          $ltouches[] = $touches;
        $touches = [];
      }
    }
    if ($touches)
      $ltouches[] = $touches;
    //echo 'ltouches='; print_r($ltouches); die();
    return $ltouches;
  }

  // fabrique une psudo-face exterior qui rassemble tous les blades extérieurs, cad dont seul un c^oté est sinon défini
  static function buildAllExterior(): void {
    $exterior = new Face('exterior', [], -1);
    foreach (self::allFaces() as $id => $face) {
      if ($id != 'exterior')
        $face->buildExterior($exterior);
    }
  }
  
  function buildExterior(Face $exterior) {
    //echo "<b>buildExterior @ $this->id</b>\n";
    $thisCoords = $this->coords()[0];
    $int = new Intervals(0, count($thisCoords));
    foreach ($this->blades as $numBlade) {
      //echo "numBlade = $numBlade\n";
      $blade = DirectBlade::get($numBlade);
      //echo "blade: $blade\n";
      foreach ($this->touches($thisCoords, $blade->coords()) as $touches)
        if ($touches[0] <> $touches[1])
          $int->add($touches[0], $touches[1]);
    }
    //$int->dump($this->id);
    foreach ($int->remaining() as $min => $max) {
      if ($max <> $min + 1) {
        //echo "exterior $this->id $min $max\n";
        $bladeCoords = array_slice($thisCoords, $min, $max-$min+1);
        $bladeNum = DirectBlade::create($this, $exterior, $min, $max, $bladeCoords);
        $this->blades[] = $bladeNum;
        $exterior->blades[] = - $bladeNum;
      }
    }
  }
};

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>topomap</title></head><body><pre>\n";

if (0) { // Test in_array()
  echo in_array('a', ['a']) ? 'oui' : 'non',"\n";
  echo in_array([0,1], [[0,1],[1,1]]) ? 'oui' : 'non',"\n";
  echo in_array([0,1], [[1,1]]) ? 'oui' : 'non',"\n";
  die("Fin ligne ".__LINE__);
}

if (0) { // fabrication du fichier de positions
  function writePosOfPolygon(GeoJFileW $posfile, string $id, array $coords) {
    foreach ($coords as $nring => $ring) {
      foreach ($ring as $npos => $pos) {
        $posfile->write([
          'type'=> 'Feature',
          'properties'=> [
            'id'=> "$id/$nring/$npos",
          ],
          'geometry'=> [
            'type'=> 'Point',
            'coordinates'=> $pos,
          ]
        ]);
      }
    }
  }
  
  $geojfile = new GeoJFile($geojfilePath);
  $posfile = new GeoJFileW(__DIR__."/pos$dept.geojson");
  
  foreach ($geojfile->quickReadFeatures() as $feature) {
    //print_r($feature);
    $id = $feature['properties']['INSEE_COM'];
    if (substr($id, 0, 2) <> $dept) continue;
    $geom = $feature['geometry'];
    if ($geom['type'] == 'Polygon') {
      writePosOfPolygon($posfile, $id, $geom['coordinates']);
    }
    else {
      foreach ($geom['coordinates'] as $no => $polygonCoords) {
        writePosOfPolygon($posfile, "$id/$no", $geom['coordinates']);
      }
    }
  }
  $posfile->close();
  die("pos$dept.geojson écrit\n");
}

$debut = time();

if (is_file(__DIR__."/topomap$dept.pser")) {
  Face::unserialize(file_get_contents(__DIR__."/topomap$dept.pser"));
}
else {
  Face::$geojfilePath = $geojfilePath;
  $geojfile = new GeoJFile($geojfilePath);
  $counter=0;
  foreach ($geojfile->quickReadFeatures() as $feature) {
    //print_r($feature);
    $id = $feature['properties']['INSEE_COM'];
    if ($dept && (substr($id, 0, 2) <> $dept)) continue;
    $geom = $feature['geometry'];
    if ($geom['type'] == 'Polygon')
      new Face("$id/u", $geom['coordinates'], $feature['ftell']);
    else {
      foreach ($geom['coordinates'] as $no => $polygonCoords) {
        new Face("$id/$no", $polygonCoords, $feature['ftell']);
      }
    }
    $counter++;
    if ($counter % 1000 == 0) echo "counter=$counter\n";
    //if (++$counter >= 100) break;
  }
  file_put_contents(__DIR__."/topomap$dept.pser", Face::serialize());
}

Face::buildAllBlades($debut);

Face::buildAllExterior();
//print_r(Face::get('exterior'));

Face::dump();
//Blade::dump();
DirectBlade::writeGeoFile(__DIR__."/lim$dept.geojson");
