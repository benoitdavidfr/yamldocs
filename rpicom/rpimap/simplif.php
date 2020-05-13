<?php
/*PhpDoc:
name: simplif.php
title: simplification en arrondissant les coord. à 3 décimales, soit 100m
doc: |
  On commence par construire une carte topologique à partir des segments produits par mklim.php
*/
require_once __DIR__.'/../geojfile.inc.php';
require_once __DIR__.'/../geojfilew.inc.php';
require_once __DIR__.'/../../../../geovect/gegeom/gegeom.inc.php';
require_once __DIR__.'/../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '2G');

if (0) { // ident. de la plus petite commune
  $geojfilePath = __DIR__.'/../data/aegeofla/AE2020COG/FRA/COMMUNE_CARTO_cor1.geojson';

  function size(array $bbox): float {
    $dlon = ($bbox[2] - $bbox[0]) * cos($bbox[1]/2/pi());
    $dlat = $bbox[3] - $bbox[1];
    return sqrt($dlon * $dlon + $dlat * $dlat);
  }
  
  $geojfile = new GeoJFile($geojfilePath);
  $dlonMin = null;
  $dlatMin = null;
  $sizeMin = null;
  foreach ($geojfile->quickReadFeatures() as $feature) {
    //print_r($feature);
    //echo Yaml::dump([$feature['id'] => $feature['bbox']]);
    $bbox = $feature['bbox'];
    $dlon = $bbox[2] - $bbox[0];
    if (($dlonMin === null) || ($dlon < $dlonMin)) {
      $dlonMin = $dlon;
      $dlonMinId = $feature['id'];
    }
    $dlat = $bbox[3] - $bbox[1];
    if (($dlatMin === null) || ($dlat < $dlatMin)) {
      $dlatMin = $dlat;
      $dlatMinId = $feature['id'];
    }
    $size = size($bbox);
    if (($sizeMin === null) || ($size < $sizeMin)) {
      $sizeMin = $size;
      $sizeMinId = $feature['id'];
    }
    //$counter++;
    //if ($counter % 10000 == 0) echo "counter=$counter\n";
    //if (++$counter >= 100) break;
  }
  echo "dLonMin: $dlonMinId => $dlonMin °\n";
  echo "dLatMin: $dlatMinId => $dlatMin °\n";
  echo printf("sizeMin: $sizeMinId => %f° = %f km\n", $sizeMin, $sizeMin/360*40000);
  /*
  dLonMin: 33103 => 0.00272 °
  dLatMin: 33103 => 0.0026299999999964 °
  sizeMin: 33103 => 0.003210° = 0.356670 km
  */
}

function lpos2lseg(array $lpos): array {
  $lseg = [];
  foreach ($lpos as $pos) {
    if (isset($posprec))
      $lseg[] = [$posprec[0], $posprec[1], $pos[0], $pos[1]];
    $posprec = $pos;
  }
  return $lseg;
}

// retourne le no de seg dans lseg1 ou -1
function segOverlaps(array $lseg1, array $lseg2): int {
  foreach ($lseg1 as $noseg => $seg) {
    if (in_array($seg, $lseg2))
      return $noseg;
  }
  return -1;
}

class Ring {
  protected $bladeNums; // [ int ] - les num. de brins dans l'ordre du cycle
  
  function __construct(array $bladeNums) { $this->bladeNums = $bladeNums; }
  
  function asArray(): array {
    foreach($this->bladeNums as $num)
      $blades[$num] = Blade::get($num)->asArray();
    return $blades;
  }
};

class Face {
  static $all; // [ id => Face ]
  protected $id;
  protected $bladeNums; // [ int ] puis [] après createRings, la liste des brins affectés à la face lors de la lecture du fichier
  protected $rings; // [ Ring ] après createRings, la face définie comme ensemble d'anneaux
  
  static function get(string $id): ?Face { return self::$all[$id] ?? null; }
  
  // Ajout d'un brin à une face
  static function getAndAddBlade(string $id, int $bladeNum): Face {
    if (!isset(self::$all[$id]))
      self::$all[$id] = new Face($id);
    self::$all[$id]->bladeNums[] = $bladeNum;
    return self::$all[$id];
  }
  
  function __construct(string $id) { $this->id = $id; $this->bladeNums = []; }
  
  function id() { return $this->id; }
  function bladeNums() { return $this->bladeNums; }
  
  function dump(): void {
    if ($this->bladeNums) {
      foreach($this->bladeNums as $num)
        $blades[$num] = Blade::get($num)->asArray();
      echo Yaml::dump([$this->id => $blades]);
    }
    else {
      $rings = [];
      foreach($this->rings as $nr => $ring) {
        $rings[$nr] = $ring->asArray();
      }
      echo Yaml::dump([$this->id => $rings], 3);
    }
  }
  
  private function bladeStartingAtPos(array $pos): int {
    foreach ($this->bladeNums as $i => $bn) {
      if (Blade::get($bn)->start() == $pos) {
        unset($this->bladeNums[$i]);
        return $bn;
      }
    }
    return 0;
  }
  
  // Création des anneaux en construisant les cycles de brins
  function createRings(): bool {
    $bladeNums = $this->bladeNums;
    $this->rings = [];
    while ($this->bladeNums) {
      $bn0 = array_pop($this->bladeNums);
      $oneCycle = [ $bn0 ];
      $pos0 = Blade::get($bn0)->start();
      $pos = Blade::get($bn0)->end();
      echo "démarrage sur bn0=$bn0\n";
      while ($nextBn = $this->bladeStartingAtPos($pos)) {
        echo "continue sur $nextBn\n";
        $oneCycle[] = $nextBn;
        $pos = Blade::get($nextBn)->end();
      }
      if ($pos <> $pos0) {
        echo "*** Erreur de createRings() sur id=$this->id avec [$pos[0], $pos[1]] <> [$pos0[0], $pos0[1]]\n";
        $this->bladeNums = $bladeNums;
        return false;
      }
      $this->rings[] = new Ring($oneCycle);
    }
    return true;
  }

  // appelée à la fin sur les faces pour lesquelles la création d'anneaux a échouée
  function clean() {
    foreach ($this->bladeNums as $i => $num1) {
      foreach ($this->bladeNums as $j => $num2) {
        if ($j > $i) {
          $lseg1 = lpos2lseg(Blade::get($num1)->coords());
          $lseg2 = lpos2lseg(Blade::get($num2)->coords());
          if (-1 <> $noseg = segOverlaps($lseg1, $lseg2)) {
            echo "Face$this->id: les brins $num1 et $num2 se superposent\n";
            //$seg = $lseg1[$noseg];
            //echo "  Seg $noseg en overlap: ",$seg[]
          }
        }
      }
    }
  }
};

// Une limite ou son inverse
class Blade {
  // récupère un brin à partir de son numéro
  static function get(int $num): Blade {
    if ($num > 0)
      return Lim::$all[$num];
    else
      return Lim::$all[-$num]->inv();
  }
  
  function asArray(): array {
    return [
      'right'=> $this->right() ? $this->right()->id() : '',
      'left'=> $this->left() ? $this->left()->id() : '',
      'start'=> $this->start(),
      'end'=> $this->end(),
    ];
  }

  // retourne la liste des segments composant le brin sous la forme d'une liste d'objets Segment
  function lSegs(): array {
    $lSegs = [];
    foreach ($this->coords() as $pos) {
      if (isset($precPos))
        $lSegs[] = new gegeom\Segment($precPos, $pos);
      $precPos = $pos;
    }
    return $lSegs;
  }
};

// Une limite entre faces
class Lim extends Blade {
  static $all=[]; // [ num => Lim ], stockage des limites, num à partir de 1
  protected $right; // Face - face à droite
  protected $left; // Face | null - face à gauche ou null si c'est l'extérieur
  protected $coords; // LPos
  
  /*static function segAlreadyExistsInABlade(array $feature, bool $verbose): bool {
    $seg = new gegeom\Segment($feature['geometry']['coordinates'][0], $feature['geometry']['coordinates'][1]);
    $rightId = $feature['properties']['right'];
    $rightFace = Face::get($rightId);
    if (!$rightFace) {
      if ($verbose)
        echo "    segAlreadyExistsInABlade: La face n'existe pas\n";
      return false;
    }
    foreach ($rightFace->bladeNums() as $bladeNum) {
      if (in_array($seg, Blade::get($bladeNum)->lSegs())) {
        if ($verbose)
          echo "    segAlreadyExistsInABlade: vrai\n";
        return true;
      }
    }
    if ($verbose)
      echo "    segAlreadyExistsInABlade: faux\n";
    return false;
  }*/
  
  // ajout à la carte d'un segment lu dans le fichier
  // Dans de nombreux cas les segments se suivent et dans ce cas on ajoute un point à la limite précédente
  /*static function addSeg(array $feature) {
    $noLim = count(self::$all);
    if (($precLim = ($noLim <> 0) ? self::$all[$noLim] : null)
      && ($precLim->right->id() == $feature['properties']['right'])
      && (($precLim->left ? $precLim->left->id() : '') == $feature['properties']['left'])
      && ($precLim->end() == $feature['geometry']['coordinates'][0])) {
        $precLim->coords[] = $feature['geometry']['coordinates'][1];
    }
    //elseif (self::segAlreadyExistsInABlade($feature, $verbose)) { echo "    result: Suppression d'un segment déjà existant\n"; }
    else { // sinon création d'une nouvelle limite et ajout des brins aux 2 faces
      $noLim = count(self::$all) + 1;
      $right = Face::getAndAddBlade($feature['properties']['right'], $noLim);
      if ($feature['properties']['left'])
        $left = Face::getAndAddBlade($feature['properties']['left'], -$noLim);
      else
        $left = null;
      self::$all[$noLim] = new Lim($right, $left, $feature['geometry']['coordinates']);
    }
  }*/
  
  static function add(array $feature) {
    $noLim = count(self::$all) + 1;
    $right = Face::getAndAddBlade($feature['properties']['right'], $noLim);
    if ($feature['properties']['left'])
      $left = Face::getAndAddBlade($feature['properties']['left'], -$noLim);
    else
      $left = null;
    self::$all[$noLim] = new Lim($right, $left, $feature['geometry']['coordinates']);
  }
  
  function __construct(Face $right, ?Face $left, array $coords) {
    $this->right = $right;
    $this->left = $left;
    $this->coords = $coords;
  }
  function right(): Face { return $this->right; }
  function left(): ?Face { return $this->left; }
  function coords(): array { return $this->coords; }
  function start(): array { return $this->coords[0]; }
  function end(): array { return $this->coords[count($this->coords)-1]; }
  
  function inv(): Inv { return new Inv($this); }
};

class Inv extends Blade {
  protected $inv; // Lim
  
  function __construct(Lim $inv) { $this->inv = $inv; }
  
  function right(): Face { return $this->inv->left(); }
  function left(): ?Face { return $this->inv->right(); }
  function coords(): array { return array_reverse($this->inv->coords()); }
  function start(): array { return $this->inv->end(); }
  function end(): array { return $this->inv->start(); }
};

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>simplif</title></head><body><pre>\n";

if (0) { // Test
  $lSegs = [
    new gegeom\Segment([0,0],[0,1]),
    new gegeom\Segment([0,1],[0,2]),
    new gegeom\Segment([0,2],[2,2]),
  ];
  print_r($lSegs);
  echo in_array(new gegeom\Segment([0,1],[0,2]), $lSegs) ? "oui\n" : "non\n";
  echo in_array(new gegeom\Segment([0,0],[0,2]), $lSegs) ? "oui\n" : "non\n";
  die("Fin test\n");
}

$geojfilePath = __DIR__.'/limcom.geojson';
$geojfile = new GeoJFile($geojfilePath);
foreach ($geojfile->quickReadFeatures() as $feature) {
  //print_r($feature);
  //echo Yaml::dump([$feature['id'] => $feature['bbox']]);
  //Lim::addSeg($feature);
  Lim::add($feature);
  //$counter++;
  //if ($counter % 10000 == 0) echo "counter=$counter\n";
  //if (++$counter >= 100) break;
}


/*foreach (['33112/u','02232/u','85029/u'] as $faceId) {
  Face::$all[$faceId]->dump();
  Face::$all[$faceId]->createRings();
  Face::$all[$faceId]->dump();
}
die();*/

$errors = [];
foreach (Face::$all as $faceId => $face) {
  if (!$face->createRings())
    $errors[] = $faceId;
}

echo count($errors)," erreurs de création d'anneau sur :\n";
foreach ($errors as $faceId) {
  Face::$all[$faceId]->dump();
  Face::$all[$faceId]->clean();
}

//file_put_contents(__DIR__.'/map.pser', [Face::serialize, Lim::serialize()]);

