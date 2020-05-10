<?php
/*PhpDoc:
name: topomap.php
title: génération du fichier des limites entre communes à partir de Ae2020Cog par construction d'un graphe d'adjacence entre communes
doc: |
  Algo:
    1) Lecture du fichier Ae2020Cog et construction des faces dans Face::$all stockées par département utilisé comme index spatial
    2) confrontation de chaque face avec les autres pour en déduire une éventuelle limite commune,
       stockage des limites dans DirectBlade
    3) confrontation de chaque face avec les limites ainsi générées pour en déduire les limites restantes qui sont celles de l'extérieur
    Le résultat n'est pas une carte topologique mais un graphe d'adjacence permettant de construire les limites communes
  Contraintes:
    - temps de traitement en carré du nbre de faces
      - utilisation des rectangles englobants pour réduire la durée des tests d'adjacence
      - gesstion d'un index géométrique très simple par département
    - la géométrie des faces ne peut être gérée en mémoire
      - chaque face renvoie vers le fichier GeoJSON en entrée dans lequel je recherche la géométrie
      - optimisation pour ne pas réouvrir le fichier GeoJSON à chaque demande
  Stats:
    - 35100 faces
  Perf:
    - sans cache sur 02+60 14'
    - avec cache de 500 objets
journal: |
  9/5/2020:
    - exécution lancée sur tte la France dans la nuit du 8 au 9/5
      - @08:41: 12280 / 35100 traités dans buildAllBlades en 454.1 min.
      - estimation 22h de durée, fin prévue à 8:41 + 454.1/12280 * (35100-12280) = 22:45
    - possibilités d'amélioration
      - les géométries des limites pourraient être écrites au fur et à mesure et pas à la fin
        afin notamment de libérer la mémoire correspondant au stockage 
        - pb la géométrie des limites est utilisée à la fin pour calculer les limites extérieures
        - stocker les intervalles au lieu de refaire les tests
      - il y a parfois 2 limites qd il pourrait y en avoir qu'une
      - la face extérieure pourrait être gérée comme null et non stockée dans Face:$all
    - améliorations:
      - afficher l'heure d'estimation de fin
      - possibilité de restreindre à plusieurs départements pour effectuer les tests sur les limites aux départements
      - gestion des trous dans les polygones des communes
      - mise en place d'un cache dans GeoJFile::quickReadOneFeature() qui exploite le fait que j'effectue la création des brins par dépt
    - relance France entière à 11:30, fin estimée 21:36
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
//$depts = ['02','60'];
$depts = ['97'];
//$depts = [];

// Permet d'identifier les intervalles de positions non couverts
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

function writeLimit(Face $rightFace, ?Face $leftFace, GeoJFileW $limGeoJFile, array $coords): void {
   $geojson = [
    'type'=> 'Feature',
    'properties'=> [
      'right'=> $rightFace->id(),
      'left'=> $leftFace ? $leftFace->id() : '',
    ],
    'geometry'=> [
      'type'=> 'LineString',
      'coordinates'=> $coords,
    ],
  ];
  $limGeoJFile->write($geojson);
}

class Face {
  static $geojfilePath; // chemin du fichier geojson des objets
  static $geojfile = null; // descripteur du fichier geojson des objets
  static protected $all=[]; // [dept => ['bbox'=> bbox, 'faces'=> [id => Face]]]
  protected $id;
  protected $bbox = null;
  //protected $coords;
  protected $ftell;
  protected $intervals = []; // liste d'objets Intervals, 1 par ring, pour enregistrer les intervalles de positions convertis en brins
  
  /*static function get(string $id): Face {
    return self::$all[$id] ?? [];
  }*/

  static function allFaces(): array { // retourne ttes les faces
    $all = [];
    foreach (self::$all as $dept => $facesOfDept) {
      $all = array_merge($all, $facesOfDept['faces']);
    }
    return $all;
  }
  
  static function select(gegeom\GBox $box): array { // retourne les faces intersectant la box
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
  
  static function dump(string $path=null) {
    if ($path)
      $file = fopen($path, 'w');
    $all = self::allFaces();
    ksort($all);
    foreach($all as $id => $face) {
      if ($file)
        fwrite($file, Yaml::dump([$id => $face->asArray()]));
      else
        echo Yaml::dump([$id => $face->asArray()]);
    }
    if ($file)
      fclose($file);
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
    foreach ($coords as $ringno => $listOfPos) {
      $this->intervals[$ringno] = new Intervals(0, count($listOfPos));
    }
    
    $dept = substr($this->id, 0, 2);
    self::$all[$dept]['faces'][$this->id] = $this;
    if ($this->bbox)
      self::$all[$dept]['bbox'] = !isset(self::$all[$dept]['bbox']) ? $this->bbox : $this->bbox->union(self::$all[$dept]['bbox']);
  }
  
  function id() { return $this->id; }
  
  function coords(): array { // retourne les coordonnées du polygone associée à la face
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
  
  static function buildAllBlades(GeoJFileW $limGeoJFile, float $debut): void { // Fabrique les brins de chaque face
    $counter = 0;
    $all = self::allFaces();
    $totalNbre = count($all);
    foreach ($all as $id => $face) {
      $face->buildBlades($limGeoJFile);
      if (++$counter % 25 == 0) {
        printf("$counter / $totalNbre traités dans buildAllBlades en %.1f min., ", (time()-$debut)/60);
        printf("fin estimée à %s\n", date('H:i', $debut + ((time()-$debut) / $counter * $totalNbre)));
      }
      //if ($counter > 20) break;
    }
    echo "Fin de buildAllBlades\n";
  }

  function buildBlades(GeoJFileW $limGeoJFile): void { // fabrique les brins de la face
    //echo "buildBlades@$this->id\n";
    $thisPolCoords = $this->coords();
    foreach (self::select($this->bbox) as $id => $face) {
      if ($face->id >= $this->id) continue;
      //echo "$this->id touches? $face->id\n";
      foreach ($thisPolCoords as $thisRingno => $thisRingCoords) {
        foreach ($face->coords() as $faceRingno => $faceRingCoords) {
          if ($listOfTouches = $this->touches($thisRingCoords, $faceRingCoords)) {
            foreach ($listOfTouches as $touches) {
              if ($touches[0] == $touches[1]) continue;
              $bladeCoords = array_slice($thisRingCoords, $touches[0], $touches[1]-$touches[0]+1);
              $bladeNum = writeLimit($this, $face, $limGeoJFile, $bladeCoords);
              $this->blades[] = $bladeNum;
              $face->blades[] = - $bladeNum;
              $this->intervals[$thisRingno]->add($touches[0], $touches[1]);
            }
            foreach ($this->touches($faceRingCoords, $thisRingCoords) as $touches) {
              if ($touches[0] == $touches[1]) continue;
              $face->intervals[$faceRingno]->add($touches[0], $touches[1]);
            }
          }
        }
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

  // fabrique une pseudo-face exterior qui rassemble tous les blades extérieurs, cad ceux dont seul un côté est sinon défini
  static function buildAllExterior(GeoJFileW $limGeoJFile): void {
    foreach (self::allFaces() as $id => $face)
      $face->buildExterior($limGeoJFile);
  }
  
  function buildExterior(GeoJFileW $limGeoJFile): void {
    foreach ($this->coords() as $ringno => $thisCoords) {
      foreach ($this->intervals[$ringno]->remaining() as $min => $max) {
        if ($max <> $min + 1) {
          //echo "exterior $this->id $min $max\n";
          $bladeCoords = array_slice($thisCoords, $min, $max-$min+1);
          writeLimit($this, null, $limGeoJFile, $bladeCoords);
        }
      }
    }
  }
};

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>topomap</title></head><body><pre>\n";

if (0) { // Test in_array()
  // vérification de la possibilité de tester qu'une position appartient à une LineString en utilisant in_array()
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

$psername = __DIR__."/topomap".implode('-',$depts).".pser";
if (is_file($psername)) {
  Face::unserialize(file_get_contents($psername));
}
else {
  Face::$geojfilePath = $geojfilePath;
  $geojfile = new GeoJFile($geojfilePath);
  $counter=0;
  foreach ($geojfile->quickReadFeatures() as $feature) {
    //print_r($feature);
    $id = $feature['properties']['INSEE_COM'];
    if ($depts && !in_array(substr($id, 0, 2), $depts)) continue;
    if (substr($id, 0, 3) <> '974') continue;
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
  file_put_contents($psername, Face::serialize());
}

$limGeoJFile = new GeoJFileW(__DIR__.'/lim'.implode('-',$depts).'.geojson');

Face::buildAllBlades($limGeoJFile, $debut);

Face::buildAllExterior($limGeoJFile);

$limGeoJFile->close();

echo Yaml::dump(['cacheStats'=> Face::$geojfile->cacheStats()]);
