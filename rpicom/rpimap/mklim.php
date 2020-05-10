<?php
/*PhpDoc:
name: mklim.php
title: création du fichier des limites entre communes à partir de Ae2020Cog/COMMUNE_CARTO par test d'adjacence entre communes
doc: |
  Algo:
    - le traitement est effectué par dalle (tile) de 2° X 2°, il y en a 29
      - cela permet de ne pas être en n2 du nbre de faces
    - pour chaque dalle
      - lecture de fichier des communes, sélection de celles intersectant la dalle et transf. en polygones (appelés Face)
      - test d'adjacence entre les ring des communes 2 à 2 pour identifier les limites entre ces rings
        - enregistrement GeoJSON de la (des) limite(s) ainsi identifiée(s)
          - seules sont enregistrées celles
            - qui intersectent la dalle et
            - qui n'intersectent pas les bords N et E de la dalle
        - marquage de cette limite dans le polygone sous la forme d'intervalles de positions
      - identification des intervalles de positions non marqués qui sont les limites extérieures
        - enregistrement avec les mêmes conditions
    - fin de traitement de la dalle
    - améliorations possibles
      - générer un fichier de communes avec bbox afin d'éviter de la calculer à chaque lecture
  Stats:
    - 35100 faces
  Perf:
    - exécution complète le 10/5 19:00 en 44'
    - le traitement par dalle évite un algo en n2 sur 35100 faces
journal: |
  10/5/2020:
    - restructuration importante
    - le traitement est effectué par dalle (tile) de 1° X 1°, il y en a 121
      - cela permet de ne plus être en n2 du nbre de faces
    - pour chaque dalle
      - lecture de fichier des communes et sélection de celles intersectant la dalle
      - test d'adjacence entre les ring des communes 2 à 2 pour identifier les limites entre ces rings
        - enregistrement de la (des) limite(s) ainsi identifiée(s)
          - seules sont enregistrées les limites
            - qui intersectent la tuile et
            - qui n'intersectent pas les bords N et E de la tuile
        - marquage de cette limite dans le polygone sous la forme d'intervalles de positions
      - identification des intervalles de positions non marqués qui sont les limites extérieures
      - enregistrement avec les mêmes conditions
    - fin de traitement de la dalle
    - bug dans 2 cas particuliers dans les commune 40323 et 82031
      - Erreur dans Intervals:add(id=40056/u/0 X 40323/0/0, min=0, max=1)
      - Erreur dans Intervals:add(id=40323/0/0 X 40135/u/0, min=0, max=9)
      - Erreur dans Intervals:add(id=82033/0/0 X 82031/2/0, min=0, max=1)
      - Erreur dans Intervals:add(id=82031/2/0 X 82033/0/0, min=0, max=1)
    - éxécution complète le 10/5 15:30 en 50'
    - améliorations possibles
      - générer un fichier de communes avec bbox afin d'éviter de la calculer à chaque lecture
    - agrandissement des dalles
    - utilisation des segments à la place des positions dans le test d'adjacence
    - exécution complète le 10/5 19:00 en 44'
    - bugs constatés
      - lim 66181 / 66025
  9/5/2020:
    - exécution lancée sur tte la France dans la nuit du 8 au 9/5
      - @08:41: 12280 / 35100 traités dans buildAllBlades en 454.1 min.
      - estimation 22h de durée, fin prévue à 8:41 + 454.1/12280 * (35100-12280) = 22:45
    - améliorations:
      - afficher l'heure d'estimation de fin
      - possibilité de restreindre à plusieurs départements pour effectuer les tests sur les limites aux départements
      - gestion des trous dans les polygones des communes
      - mise en place d'un cache dans GeoJFile::quickReadOneFeature() qui exploite le fait que j'effectue la création des brins par dépt
    - relance France entière à 11:30, fin estimée 21:36
    - restructuration importante
      - écriture des limites au fur et à mesure de leur création sans les conserver en mémoire
  8/5/2020:
    - première version proto testée sur les dépts 21 et 29
*/
//ini_set('memory_limit', '2048M');
ini_set('memory_limit', '2G');

require_once __DIR__.'/../geojfile.inc.php';
require_once __DIR__.'/../geojfilew.inc.php';
require_once __DIR__.'/../../../../geovect/gegeom/gegeom.inc.php';
require_once __DIR__.'/../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$geojfilePath = __DIR__.'/../data/aegeofla/AE2020COG/FRA/COMMUNE_CARTO.geojson';

// Permet d'identifier les intervalles de positions non couverts
// Il pourrait être plus simple de gérer des intervalles de segments
class Intervals {
  protected $min; // min global des intervalles
  protected $max; // max global des intervalles
  protected $subs = []; // [min => max] - liste des intervalles couverts
  
  function __construct(int $min=null, int $max=null) { $this->min = $min; $this->max = $max; }
  
  // ajout d'un intervalle de positions couvert
  function add(string $id, int $min, int $max) {
    //echo "add($min, $max) sur $id\n";
    if (isset($this->subs[$min]))
      throw new Exception("Erreur dans Intervals:add(id=$id, min=$min, max=$max)\n");
    $this->subs[$min] = $max;
  }
  
  function dump(string $label) {
    ksort($this->subs);
    echo Yaml::dump([$label=> ['min'=>$this->min, 'int'=> $this->subs, 'max'=>$this->max]], 3, 2);
  }
  
  // liste d'intervalles de positions non couvertes ss la forme [min => max]
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

class Tile extends gegeom\GBox { // Définition des dalles utilisées pour balayer les communes
  const DLON = 1;
  const DLAT = 1;
  static $all = []; // liste des tuiles nécessaires pour couvrir l'espace ciblé
  
  // ajout d'un ensemble de tuiles par définition d'un espace géographique [lonmin, latmin, lonmax, latmax]
  static function add(array $elt, float $dlon=self::DLON, float $dlat=self::DLAT): void {
    for($lon = $elt[0]; $lon < $elt[2]; $lon += $dlon) {
      for($lat = $elt[1]; $lat < $elt[3]; $lat += $dlat) {
        $tile = new self([$lon, $lat, $lon + $dlon, $lat + $dlat]);
        if (!in_array($tile, self::$all))
          self::$all[] = $tile;
      }
    }
  }
  
  static function geojson() { // dessin des tuiles pour vérifier leur couverture 
    $file = new GeoJFileW(__DIR__."/tiles.geojson");
    foreach (self::$all as $tile) {
      //echo "lon=$lon, lat=$lat\n";
      $geojson = [
        'type'=> 'Feature',
        'properties'=> [
          'swLatLon'=> $tile->min[1].' X '.$tile->min[0],
        ],
        'geometry'=> [
          'type'=> 'Polygon',
          'coordinates'=> $tile->polygon(),
        ],
      ];
      $file->write($geojson);
    }
    $file->close();
  }

  function edges() { // les 4 bords de la tuile
    return [
      'W'=> new gegeom\Segment($this->southWest(), $this->northWest()),
      'N'=> new gegeom\Segment($this->northWest(), $this->northEast()),
      'E'=> new gegeom\Segment($this->southEast(), $this->northEast()),
      'S'=> new gegeom\Segment($this->southWest(), $this->southEast()),
    ];
  }

  // teste l'intersection de la tuile avec une ligne brisée
  function intersectsListOfPos(array $lpos): bool {
    $precpos = null;
    foreach ($lpos as $pos) {
      if ($this->posInBBox($pos))
        return true;
      if ($precpos) {
        $seg = new gegeom\Segment($precpos, $pos);
        foreach ($this->edges() as $edge)
          if ($seg->intersects($edge))
            return true;
      }
      $precpos = $pos;
    }
    return false;
  }

  // teste l'intersection de la ligne brisée $lpos avec l'un des bord $edges de la tuile
  function edgesIntersectsListOfPos(array $edges, array $lpos): bool {
    $precpos = null;
    foreach ($lpos as $pos) {
      if ($precpos) {
        $seg = new gegeom\Segment($precpos, $pos);
        foreach ($this->edges() as $c => $edge)
          if (in_array($c, $edges) && $seg->intersects($edge))
            return true;
      }
      $precpos = $pos;
    }
    return false;
  }

  // écrit une limite dans le fichier GeoJSON
  // si la limite n'intersecte pas la tuile alors je ne la conserve pas
  // si la limite intersecte le bord N ou E alors je ne la conserve pas
  function writeLimit(Face $rightFace, ?Face $leftFace, GeoJFileW $limGeoJFile, array $lpos): void {
    if ($this->intersectsListOfPos($lpos) && !$this->edgesIntersectsListOfPos(['N','E'], $lpos))
      $limGeoJFile->write([
        'type'=> 'Feature',
        'properties'=> [
          'right'=> $rightFace->id(),
          'left'=> $leftFace ? $leftFace->id() : '',
        ],
        'geometry'=> [
          'type'=> 'LineString',
          'coordinates'=> $lpos,
        ],
      ]);
  }
};

// Il s'agit des faces du graphe des communes, cad un objet par polygone
class Face {
  static protected $all=[]; // [id => Face] - stockage des faces créées
  protected $id; // l'id de la face sous la forme {codeInsee}/{nopol|u}
  protected $bbox; // bbox de la face
  protected $coords; // coordonnées GeoJSON du polygone
  protected $intervals = []; // liste d'objets Intervals, 1 par ring, pour enregistrer les intervalles de pos. convertis en limites
  
  // réinitialise les propriétés de classe
  static function init(): void { self::$all = []; }
  
  // Filtre les polygones intersectant la dalle et les enregistre dans self::$all
  // décompose les MultiPolygon en une face par polygone
  static function create(array $feature, Tile $tile) {
    $id = $feature['properties']['INSEE_COM'];
    if ($feature['geometry']['type'] == 'Polygon') {
      $polygon = gegeom\Geometry::fromGeoJSON($feature['geometry']);
      $bbox = $polygon->bbox();
      if ($bbox->intersects($tile))
        new Face("$id/u", $bbox, $feature['geometry']['coordinates']);
    }
    else {
      foreach ($feature['geometry']['coordinates'] as $no => $polygonCoords) {
        $polygon = gegeom\Geometry::fromGeoJSON(['type'=>'Polygon', 'coordinates'=> $polygonCoords]);
        $bbox = $polygon->bbox();
        if ($bbox->intersects($tile))
          new Face("$id/$no", $bbox, $polygonCoords);
      }
    }
  }
  
  function __construct(string $id, gegeom\GBox $bbox, array $coords) {
    //echo "__construct($id, bbox, $ftell)\n";
    $this->id = $id;
    $this->coords = $coords;
    $this->bbox = $bbox;
    foreach ($coords as $ringno => $listOfPos)
      $this->intervals[$ringno] = new Intervals(0, count($listOfPos)-1);
    self::$all[$this->id] = $this;
  }
  
  function __toString(): string { return "Face $this->id"; }
  
  function id() { return $this->id; }
  
  function coords(): array { return $this->coords; } // retourne les coordonnées du polygone associée à la face
  
  static function buildAllLimits(gegeom\GBox $tile, GeoJFileW $limGeoJFile, float $debut): void { // Fabrique les lims de chaque face
    $counter = 0;
    $totalNbre = count(self::$all);
    foreach (self::$all as $id => $face) {
      $face->buildLimits($tile, $limGeoJFile);
      if (++$counter % 100 == 0) {
        printf("$counter / $totalNbre traités dans buildAllLimits en %.1f min.\n", (time()-$debut)/60);
      }
      //if ($counter > 20) break;
    }
    printf("$totalNbre traités dans buildAllLimits en %.1f min.\n", (time()-$debut)/60);
  }

  function buildLimits(Tile $tile, GeoJFileW $limGeoJFile): void { // fabrique les limites de la face
    //echo "buildBlades@$this->id\n";
    $thisPolCoords = $this->coords();
    foreach (self::$all as $id => $face) {
      if (($face->id >= $this->id) || !$this->bbox->intersects($face->bbox)) continue;
      //echo "$this->id touches? $face->id\n";
      foreach ($thisPolCoords as $thisRingno => $thisRingCoords) {
        foreach ($face->coords() as $faceRingno => $faceRingCoords) {
          if ($listOfTouches = $this->touches($thisRingCoords, $faceRingCoords)) {
            try {
              foreach ($listOfTouches as $touches) {
                $limCoords = array_slice($thisRingCoords, $touches[0], $touches[1]-$touches[0]+2);
                $tile->writeLimit($this, $face, $limGeoJFile, $limCoords);
                // gestion des intervalles de no de point et pas de no de segment, à REVOIR éventuellement
                $this->intervals[$thisRingno]->add("$this->id/$thisRingno X $face->id/$faceRingno", $touches[0], $touches[1]+1);
              }
              foreach ($this->touches($faceRingCoords, $thisRingCoords) as $touches) {
                $face->intervals[$faceRingno]->add("$face->id/$faceRingno X $this->id/$thisRingno", $touches[0], $touches[1]+1);
              }
            }
            catch(Exception $e) {
              echo "Exception ",$e->getMessage()," sur $this ->buildLimits()\n";
              throw new Exception($e->getMessage());
            }
          }
        }
      }
    }
  }
  
  // retourne un array d'array de 2 indices correspondant aux positions de début et de fin des points constituant la limite partagée
  /*function touches(array $thisCoords, array $faceCoords): array {
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
  }*/

  // construit une liste de segments à partir d'une liste de points ; un segment est un couche de positions
  static function lPos2lSegs(array $lPos, bool $reverse): array {
    $lSegs = [];
    $precPos = null;
    foreach($lPos as $pos) {
      if ($precPos) {
        if (!$reverse)
          $lSegs[] = [$precPos, $pos];
        else
          $lSegs[] = [$pos, $precPos];
      }
      $precPos = $pos;
    }
    return $lSegs;
  }
  
  // versions segs, prend 2 listes de Pos 
  // retourne un array d'array de 2 indices correspondant aux positions de début et de fin des points constituant la limite partagée
  static function touches(array $thisLPos, array $faceLPos): array {
    $ltouches = []; // array d'array de 2 indices
    $touches = []; // array de 2 indices
    $faceSegs = self::lPos2lSegs($faceLPos, true);
    foreach (self::lPos2lSegs($thisLPos, false) as $iseg => $seg) {
      $in = in_array($seg, $faceSegs);
      //echo $in ? "$iseg dans faceSegs\n" : "$iseg hors faceSegs\n";
      if ($in && !$touches) { // 1er seg commun
         $touches = [$iseg, $iseg];
      }
      elseif ($in && $touches && ($iseg == $touches[1]+1)) { // seg commun suivant
        $touches[1] = $iseg;
      }
      elseif (!$in && $touches) { // fin de ligne commune
        $ltouches[] = $touches;
        $touches = [];
      }
    }
    if ($touches)
      $ltouches[] = $touches;
    //echo Yaml::dump(['ltouches'=> $ltouches], 2, 2);
    return $ltouches;
  }

  // fabrique une pseudo-face exterior qui rassemble tous les blades extérieurs, cad ceux dont seul un côté est sinon défini
  static function buildAllExterior(Tile $tile, GeoJFileW $limGeoJFile): void {
    foreach (self::$all as $id => $face)
      $face->buildExterior($tile, $limGeoJFile);
  }
  
  function buildExterior(Tile $tile, GeoJFileW $limGeoJFile): void {
    //echo "exterior $this->id\n";
    foreach ($this->coords() as $ringno => $thisCoords) {
      //$this->intervals[$ringno]->dump("$this->id/$ringno");
      foreach ($this->intervals[$ringno]->remaining() as $min => $max) {
        if ($max <> $min + 1) {
          //echo "exterior $this->id $min $max\n";
          $limCoords = array_slice($thisCoords, $min, $max-$min+1);
          $tile->writeLimit($this, null, $limGeoJFile, $limCoords);
        }
      }
    }
  }
};

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>mklim</title></head><body><pre>\n";

if (0) { // Test in_array()
  // vérification de la possibilité de tester qu'une position appartient à une LineString en utilisant in_array()
  echo in_array('a', ['a']) ? 'oui' : 'non',"\n";
  echo in_array([0,1], [[0,1],[1,1]]) ? 'oui' : 'non',"\n";
  echo in_array([0,1], [[1,1]]) ? 'oui' : 'non',"\n";
  die("Fin ligne ".__LINE__);
}

if (0) { // fabrication du fichier de positions des départements $depts
  $depts = ['97'];
  
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
  
  foreach ($depts as $dept) {
    $posfile = new GeoJFileW(__DIR__."/pos$dept.geojson");
  
    foreach ($geojfile->quickReadFeatures() as $feature) {
      //print_r($feature);
      $id = $feature['properties']['INSEE_COM'];
      if (substr($id, 0, 2) <> $dept) continue;
      if ($feature['geometry']['type'] == 'Polygon') {
        writePosOfPolygon($posfile, "$id/u", $feature['geometry']['coordinates']);
      }
      else {
        foreach ($feature['geometry']['coordinates'] as $no => $polygonCoords) {
          writePosOfPolygon($posfile, "$id/$no", $polygonCoords);
        }
      }
    }
    $posfile->close();
    echo "pos$dept.geojson écrit\n";
  }
  die("Fin pos.geojson\n");
}

if (0) { // dessin grille
  $d = 1; // taille
  $grille = new GeoJFileW(__DIR__."/grille$d.geojson");
  for($lon = -180; $lon < 180; $lon += $d) {
    for($lat = -70; $lat < 70; $lat += $d) {
      //echo "lon=$lon, lat=$lat\n";
      $geojson = [
        'type'=> 'Feature',
        'properties'=> [
          'lon'=> $lon.' '.($lon+$d),
          'lat'=> $lat.' '.($lat+$d),
        ],
        'geometry'=> [
          'type'=> 'Polygon',
          'coordinates'=> [[
            [$lon, $lat],
            [$lon, $lat+$d],
            [$lon+$d, $lat+$d],
            [$lon+$d, $lat],
            [$lon, $lat],
          ]],
        ],
      ];
      $grille->write($geojson);
    }
  }
  $grille->close();
  die("Fin grille ligne ".__LINE__);
}

$debut = time();

/*Tile::add([-6, 46.1, -2, 48.6], 4, 4); // Bretagne
Tile::add([ 8, 48.1,  9, 49.1]); // Strasbourg
Tile::add([ 0, 50.1,  6, 52.1], 6, 2); // Nord
Tile::add([-2, 42.1,  8, 50.1], 2, 2); // Reste de FXX hors Corse
// { name: FXX hors Corse, westlimit: -5.16, southlimit: 42.32, eastlimit: 8.24, northlimit: 51.09 }
Tile::add([ 8, 41.1, 10, 43.1], 2, 2); // { name: Corse, westlimit: 8.53, southlimit: 41.33, eastlimit: 9.57, northlimit: 43.03 }
Tile::add([-61.9, 15.7, -60.9, 16.7]); // { name: GLP, westlimit: -61.81, southlimit: 15.83, eastlimit: -61.00, northlimit: 16.52 }
Tile::add([-61.5, 14.0, -60.5, 15.0]); // { name: MTQ, westlimit: -61.24, southlimit: 14.38, eastlimit: -60.80, northlimit: 14.89 }
Tile::add([-55, 2, -51, 6], 4, 4); // { name: GUF, westlimit: -54.61, southlimit: 2.11, eastlimit: -51.63, northlimit: 5.75 }
Tile::add([55, -21.5, 56, -20.5]); // { name: REU, westlimit: 55.21, southlimit: -21.40, eastlimit: 55.84, northlimit: -20.87 }
*/Tile::add([44.5, -13.2, 45.5, -12.2]); // { name: MYT, westlimit: 44.95, southlimit: -13.08, eastlimit: 45.31, northlimit: -12.58 }

//Tile::add([-1,43.1,0,44.1]);
//Tile::add([-0.8,44.0,-0.4,44.2], 0.4, 0.2); // debug 40323

if (0) { // fabrication du fichier geojson des dalles pour contrôler la couverture
  Tile::geojson();
  die("Fin dalles ligne ".__LINE__."\n");
}

$geojfile = new GeoJFile($geojfilePath);
$limGeoJFile = new GeoJFileW(__DIR__.'/limcom.geojson');

$nbTiles = count(Tile::$all);
foreach (Tile::$all as $notile => $tile) {
  echo "Traitement de la fenêtre $notile / $nbTiles : $tile\n";

  Face::init();
  $counter=0;
  foreach ($geojfile->quickReadFeatures() as $feature) {
    //print_r($feature);
    Face::create($feature, $tile);
    $counter++;
    if ($counter % 10000 == 0) echo "counter=$counter\n";
    //if (++$counter >= 100) break;
  }
  echo "Fin de la lecture de $geojfilePath\n";

  Face::buildAllLimits($tile, $limGeoJFile, $debut);

  Face::buildAllExterior($tile, $limGeoJFile);
}

$limGeoJFile->close();
printf("Fin mklim en %.1f min.\n", (time()-$debut)/60);
