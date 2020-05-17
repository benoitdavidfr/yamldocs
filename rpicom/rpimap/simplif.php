<?php
/*PhpDoc:
name: simplif.php
title: simplification en arrondissant les coord. à 3 décimales, soit 100m
doc: |
  On commence par construire une carte topologique à partir des limites produites par mklim.php
  Puis:
    - on concatene les limites séparées par un noeud connectant exactement ces 2 limites
    - on supprime les petits polygones dans le cas de communes en possédant plusieurs
journal: |
  17/5/2020:
    - mise au point de l'algo de concaténation de limites

*/
require_once __DIR__.'/../geojfile.inc.php';
require_once __DIR__.'/../geojfilew.inc.php';
require_once __DIR__.'/../../../../geovect/gegeom/gegeom.inc.php';
require_once __DIR__.'/../../../../geovect/geom2d/geom2d.inc.php';
require_once __DIR__.'/../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '4G');

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

class Ring {
  protected $bladeNum; // int - le num. d'un brin du cycle, les autres sont déduits par Blade::fi()
  
  function __construct(array $bladeNums) {
    $this->bladeNum = $bladeNums[0];
    foreach ($bladeNums as $bladeNum) {
      if (isset($prec))
        Blade::get($prec)->setFiNum($bladeNum);
      $prec = $bladeNum;
    }
    Blade::get($prec)->setFiNum($bladeNums[0]);
  }
  
  function bladeNums(): array { // reconstitue la liste des num. de brins de l'anneau
    //echo "Fabrication du cycle bladeNums\n";
    $bladeNums = [];
    $bladeNum = $this->bladeNum;
    //echo "  bladeNum=$bladeNum\n";
    for ($counter=0; $counter<100000; $counter++) {
      $bladeNums[] = $bladeNum;
      $bladeNum = Blade::get($bladeNum)->fiNum();
      //echo "  bladeNum=$bladeNum\n";
      if ($bladeNum == $this->bladeNum) {
        //echo Yaml::dump(['bladeNums()'=> $bladeNums]);
        return $bladeNums;
      }
    }
    throw new Exception("Nbre d'itérations max atteint pour bladeNum = $this->bladeNum");
  }
  
  function asArray(): array { return $this->bladeNums(); }

  function coords(): array { // LPos
    $coords = [];
    foreach ($this->bladeNums() as $num) {
      if ($coords)
        array_pop($coords);
      $coords = array_merge($coords, Blade::get($num)->coords());
    }
    return $coords;
  }
  
  function replaceBladeNum(int $old, int $new): void { // remplace un no de brin par un autre 
    if ($this->bladeNum == $old)
      $this->bladeNum = $new;
  }
  
  // calcul de surface en coord. géo. avec résultat en ha
  function areaHa(): float {
    // 1° carré = (40 000 km/360°) carré x cos(fi)
    $lPoints = []; // liste d'objets Point
    foreach ($this->coords() as $pos)
      $lPoints[] = new Point(['x'=> $pos[0], 'y'=> $pos[1]]);
    $lineString = new LineString($lPoints);
    return - $lineString->area() // en degrés carrés
      * (40000 * 40000 / 360 / 360) * cos($pos[1]/180*pi()) // en km carrés
      * 100; // en ha
  }

  // traitement des cas simples
  /*function delete(): bool {
    if (count($this->bladeNums) == 1) { // cas simple d'une ile en mer ou dans une autre commune
      $num = $this->bladeNums[0];
      if (Blade::get($num)->left())
        Blade::get($num)->left()->deleteHoleDefinedByOneBlade(-$num);
      Blade::removeFromAll($num);
      return true;
    }
    if (count($this->bladeNums) == 2) { // cas d'une langue entre 2 communes
      $num1 = $this->bladeNums[0]; // celui que je vais supprimer
      $num2 = $this->bladeNums[1]; // celui qui va rester
      if (Blade::get($num1)->left() == null) {
        $tmp = $num2; $num2 = $num1; $num1 = $tmp;
      }
      Blade::get($num2)->setLeft(Blade::get($num1)->left());
      Blade::removeFromAll($num1);
      return true;
    }
    return false;
  }
  
  function posOfBladeNumInList(int $bladeNum): int {
    foreach ($this->bladeNums as $i => $num)
      if ($num == $bladeNum)
        return $i;
    throw new Exception("Erreur dans Ring::posOfBladeNumInList()");
  }
  
  function precAndNextBladeNumInList(int $bladeNum): array {
    $ipos = $this->posOfBladeNumInList($bladeNum);
    if ($ipos == 0)
      return [$this->bladeNums[count($this->bladeNums)-1], $this->bladeNums[1]];
    elseif ($ipos == (count($this->bladeNums)-1))
      return [$this->bladeNums[$ipos-1], $this->bladeNums[0]];
    else
      return [$this->bladeNums[$ipos-1], $this->bladeNums[$ipos+1]];
  }
  
  // si $bladeNum appartient au cycle alors le retire et si $pos alors modifie les positions des brins avant et après
  function deleteLim(int $bladeNum, array $pos=[]): bool {
    if ((count($this->bladeNums)==1) && ($this->bladeNums[0] == $bladeNum))
      throw new Exception("Erreur dans Ring::deleteLim()");
    if (!in_array($bladeNum, $this->bladeNums))
      return false;
    if ($pos) {
      list($precBNum, $nextBNum) = $this->precAndNextBladeNumInList($bladeNum);
      Blade::get($precBNum)->setEnd($pos);
      Blade::get($nextBNum)->setStart($pos);
    }
    $ipos = $this->posOfBladeNumInList($bladeNum);
    unset($this->bladeNums[$ipos]);
    $this->bladeNums = array_values($this->bladeNums);
    return true;
  }*/
};

// Polygone défini par des rings, 1 pour l'extérieur, les autres sont les trous
class Face {
  static $all; // [ id => Face ]
  static $exterior; // Face - la face extérieure qui n'a pas d'extérieur et qui a comme trou chaque composante connexe de la carte
  protected $id;
  protected $bladeNums; // [ int ] puis [] après createRings, la liste des brins affectés à la face lors de la lecture du fichier
  protected $rings; // [ Ring ] après createRings, la face définie comme ensemble d'anneaux
  
  static function get(string $id): ?Face { return self::$all[$id] ?? null; }
  
  static function getAndAddBlade(string $id, int $bladeNum): Face { // Ajout d'un brin à une face
    if (!isset(self::$all[$id]))
      self::$all[$id] = new Face($id);
    self::$all[$id]->bladeNums[] = $bladeNum;
    return self::$all[$id];
  }
  
  function __construct(string $id) { $this->id = $id; $this->bladeNums = []; }
  
  function id() { return $this->id; }
  function bladeNums() { return $this->bladeNums; }
  
  function asArray() {
    if ($this->bladeNums) {
      foreach($this->bladeNums as $num)
        $blades[$num] = Blade::get($num)->asArray();
      return $blades;
    }
    else {
      $rings = [];
      foreach($this->rings as $nr => $ring)
        $rings[$nr] = $ring->asArray();
      return $rings;
    }
  }
  
  private function bladeStartingAtPos(array $pos): int { // utilisée par createRings() 
    foreach ($this->bladeNums as $i => $bn) {
      if (Blade::get($bn)->start() == $pos) {
        unset($this->bladeNums[$i]);
        return $bn;
      }
    }
    return 0;
  }
  
  function createRings(): bool { // Création des anneaux en construisant les cycles de brins
    $bladeNums = $this->bladeNums;
    $this->rings = [];
    while ($this->bladeNums) {
      $bn0 = array_pop($this->bladeNums);
      $oneCycle = [ $bn0 ];
      $pos0 = Blade::get($bn0)->start();
      $pos = Blade::get($bn0)->end();
      //echo "démarrage sur bn0=$bn0\n";
      while ($nextBn = $this->bladeStartingAtPos($pos)) {
        //echo "continue sur $nextBn\n";
        $oneCycle[] = $nextBn;
        $pos = Blade::get($nextBn)->end();
      }
      if ($pos <> $pos0) {
        //echo "*** Erreur de createRings() sur id=$this->id avec [$pos[0], $pos[1]] <> [$pos0[0], $pos0[1]]\n";
        $this->bladeNums = $bladeNums;
        return false;
      }
      $this->rings[] = new Ring($oneCycle);
    }
    return true;
  }

  static function createExterior(): void { // crée la face extérieure et l'enregistre dans self::$exterior
    //echo "createExterior()\n";
    self::$exterior = new Face('exterior');
    self::$all['exterior'] = self::$exterior;
    // affecte à la face tous les brins ayant un côté à l'exterieur
    foreach (Lim::$all as $num => $lim) {
      if (!$lim->left()) {
        $lim->setLeft(self::$exterior);
        self::$exterior->bladeNums[] = - $num;
      }
    }
    if (!self::$exterior->createRings())
      echo "Erreur de création de l'extérieur\n";
  }
  
  function replaceBladeNum(int $old, int $new): void { // remplace un no de brin par un autre 
    foreach ($this->rings as $ring)
      $ring->replaceBladeNum($old, $new);
  }
    
  function areaHa(): float { // calcule la surface de la face comme somme des surfaces de ses anneaux
    $area = 0;
    foreach ($this->rings as $ring)
      $area += $ring->areaHa();
    return $area;
  }
  
  /*function delete(): bool { // supprime la face, renvoie false si pas possible, cad non implémenté
    if (count($this->rings) == 1) {
      if ($this->rings[0]->delete()) {
        unset(Face::$all[$this->id]);
        return true;
      }
    }
    return false;
  }
  
  function deleteHoleDefinedByOneBlade(int $bladeNum): void { // supprime un trou dans la face défini par un brin
    foreach ($this->rings as $numRing => $ring) {
      if ($ring->bladeNums() == [$bladeNum]) {
        unset($this->rings[$numRing]);
        return;
      }
    }
    throw new Exception("Face::deleteHoleDefinedByOneBlade() non effectué");
  }
  
  function deleteLim(int $bladeNum, array $pos) {
    foreach ($this->rings as $ring) {
      if ($ring->deleteLim($bladeNum, $pos))
        return;
    }
    throw new Exception("Erreur dans Face::deleteLim()");
  }*/
};

// Une limite ou son inverse
abstract class Blade {
  // récupère un brin à partir de son numéro
  static function get(int $num): Blade {
    if ($num > 0) {
      if (isset(Lim::$all[$num]))
        return Lim::$all[$num];
      else
        throw new Exception("Erreur de Blade::get($num)");
    }
    else {
      if (isset(Lim::$all[-$num]))
        return Lim::$all[-$num]->inv();
      else
        throw new Exception("Erreur de Blade::get($num)");
    }
  }

  static function removeBladeFromAll(int $num): void { unset(Lim::$all[abs($num)]); } // supprime le brin et son inverse
  
  abstract function right(): ?Face;
  abstract function left(): ?Face;
  abstract function coords(): array;
  abstract function start(): array;
  abstract function setStart(array $pos): void;
  abstract function end(): array;
  abstract function setEnd(array $pos): void;
  abstract function fiNum(): int; // renvoie le numéro du brin suivant dans la définition des faces
  function fi(): ?Blade { return $this->fiNum() ? Blade::get($this->fiNum()) : null; } // renvoie le brin suivant ou null
  abstract function setFiNum(int $num): void;
  
  /*static function fiInvNum(int $bnum0): int { // calcul de fi-1 sur les numéros de brins
    $bnum = $bnum0;
    $counter = 0;
    while (Blade::get($bnum)->fiNum() <> $bnum0) {
      $bnum = Blade::get($bnum)->fiNum();
      if (++$counter > 100)
        throw new Exception("Boucle détectée dans Blade::fiInvNum()");
    }
    echo "fiInvNum($bnum0) = $bnum\n";
    return $bnum;
  }*/
  
  // sigma = alpha o fi - les cycles de sigma sont les brins qui arrivent au même noeud
  function sigmaNum(): int { return - $this->fiNum(); }
  function setSigmaNum(int $num) { $this->setFiNum(-$num); }
    
  static function sigmaInvNum(int $bnum0): int { // calcul de sigma-1 sur les numéros de brins
    $bnum = $bnum0;
    $counter = 0;
    while (Blade::get($bnum)->sigmaNum() <> $bnum0) {
      $bnum = Blade::get($bnum)->sigmaNum();
      if (++$counter > 100)
        throw new Exception("Boucle détectée dans Blade::sigmaInvNum()");
    }
    //echo "sigmaInvNum($bnum0) = $bnum\n";
    return $bnum;
  }
  
  function asArray(): array {
    return [
      'right'=> $this->right() ? $this->right()->id() : '',
      'left'=> $this->left() ? $this->left()->id() : '',
      'fiNum'=> $this->fiNum(),
      'fiOfInv'=> $this->fiOfInv(),
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
  
  // concatene 2 brins distincts séparés par un noeud qui ne connecte qu'eux
  // $bnum0 est le numéro du brin courant conservé, $next est le brin en séquence à supprimer
  static function concat(int $bnum0, int $next): void {
    //echo "Concaténation des brins $bnum0 et $next\n";
    //echo Yaml::dump([$bnum0=> Blade::get($bnum0)->asArray(), $next=> Blade::get($next)->asArray()]);
    $sigmaInvNum = Blade::sigmaInvNum($next); // le no du brin qui pointe par sigma vers next
    //echo "sigmaInvNum = $sigmaInvNum\n";
    Blade::get($sigmaInvNum)->setSigmaNum($bnum0); // modif. du sigma pour enlever next
    //echo "$sigmaInvNum ->setSigmaNum($bnum0)\n";
    Blade::get($bnum0)->setFiNum(Blade::get($next)->fiNum()); // reprise du fi de $next
    //echo "$bnum0 ->setFiNum(",Blade::get($next)->fiNum(),")\n";
    $nextCoords = Blade::get($next)->coords();
    array_shift($nextCoords);
    Blade::get($bnum0)->setCoords(array_merge(Blade::get($bnum0)->coords(), $nextCoords));
    Blade::get($next)->right = null;
    Blade::get($next)->left = null;
    Blade::get($next)->fiNum = 0;
    Blade::get($next)->fiOfInv = 0;
    Blade::get($next)->setCoords([]);
    unset(Lim::$all[abs($next)]);
    // Si le brin supprimé est utilisé dans un anneau alors il doit être remplacé
    Blade::get($bnum0)->right()->replaceBladeNum($next, $bnum0);
    Blade::get($bnum0)->left()->replaceBladeNum(-$next, -$bnum0);
  }
};

// Une limite entre faces
class Lim extends Blade {
  static $all=[]; // [ num => Lim ], stockage des limites, num à partir de 1
  protected $right; // Face - face à droite
  protected $left; // Face | null - face à gauche, si c'est l'extérieur soit null soit la Face spéciale exterior
  protected $fiNum; // Int - le brin suivant du brin dans l'anneau défini par son numéro
  protected $fiOfInv; // Int - le brin suivant du brin inverse dans l'anneau, défini par son numéro
  protected $coords; // LPos
  
  // méthode utilisée pour construire initialement la carte à partir des limites lues dans le fichier GeoJSON
  static function add(array $feature) {
    $noLim = count(self::$all) + 1;
    $right = Face::getAndAddBlade($feature['properties']['right'], $noLim);
    if ($feature['properties']['left'])
      $left = Face::getAndAddBlade($feature['properties']['left'], -$noLim);
    else
      $left = null;
    self::$all[$noLim] = new Lim($right, $left, $feature['geometry']['coordinates']);
  }
  
  // convertit les références aux faces en identifiant pour préparer la serialization
  /*static function deref(): array {
    $lims = [];
    foreach (self::$all as $num => $lim) {
      $lims[$num] = [
        'right'=> $lim->right->id(),
        'left'=> $lim->left ? $lim->left->id() : '',
        'coords'=> $lim->coords,
      ];
    }
    return $lims;
  }*/
  
  // reconvertit les id des faces en références après la déserialization
  /*static function reref(array $lims) {
    foreach ($lims as $num => $lim) {
      self::$all[$num] = new self(
        Face::get($lim['right']),
        $lim['left'] ? Face::get($lim['left']) : null,
        $lim['coords']
      );
    }
  }*/
  
  function __construct(Face $right, ?Face $left, array $coords) {
    $this->right = $right;
    $this->left = $left;
    $this->coords = $coords;
    $this->fiNum = 0;
    $this->fiOfInv = 0;
  }
  
  function right(): Face { return $this->right; }
  function setRight(?Face $face): void { $this->right = $face; }
  function left(): ?Face { return $this->left; }
  function setLeft(?Face $face): void { $this->left = $face; }
  function coords(): array { return $this->coords; }
  function setCoords(array $coords): void { $this->coords = $coords; }
  function start(): array { return $this->coords[0]; }
  function setStart(array $pos): void { $this->coords[0] = $pos; }
  function end(): array { return $this->coords[count($this->coords)-1]; }
  function setEnd(array $pos): void { $this->coords[count($this->coords)-1] = $pos; }
  function inv(): Inv { return new Inv($this); }
  function fiNum(): int { return $this->fiNum; }
  function setFiNum(int $num): void { $this->fiNum = $num; }
  function fiOfInv(): int { return $this->fiOfInv; }
  function setFiOfInv(int $num): void { $this->fiOfInv = $num; }
  
  static function segLengthKm($pos0, $pos1): float { // longueur d'un segment en km
    $dlon = ($pos1[0] - $pos0[0]) * 40000/360 * cos($pos0[1]/180*pi());
    $dlat = ($pos1[1] - $pos0[1]) * 40000/360;
    //echo "dlon=$dlon, dlat=$dlat, length=",sqrt($dlon*$dlon + $dlat*$dlat),"\n";
    return sqrt($dlon*$dlon + $dlat*$dlat);
  }
  
  function lengthKm(): float { // longueur de la limite en km
    $length = 0;
    foreach ($this->coords as $pos) {
      if (isset($precpos))
        $length += self::segLength($precpos, $pos);
      $precpos = $pos;
    }
    return $length;
  }
  
  /*function delete(int $numLim): bool { // supression de la limite
    $start = $this->start();
    $end = $this->end();
    $newPos = [($start[0]+$end[0])/2, ($start[1]+$end[1])/2];
    $this->right->deleteLim($numLim, $newPos);
    if ($this->left)
      $this->left->deleteLim(-$numLim, $newPos);
    unset(self::$all[$numLim]);
    return true;
  }*/
  
  function geojson(): array {
    $lpos = [];
    foreach ($this->coords as $pos) {
      $pos = [round($pos[0], 3), round($pos[1], 3)];
      if (!isset($precPos) || ($pos <> $precPos))
        $lpos[] = $pos;
      $precPos = $pos;
    }
    if (count($lpos)==1)
      throw new Exception("Erreur dans Lim::geojson()");
    return [
      'type'=> 'Feature',
      'properties'=> [
        'right'=> $this->right ? $this->right->id() : '',
        'left'=> $this->left ? $this->left->id() : '',
      ],
      'geometry'=> [
        'type'=> 'LineString',
        'coordinates'=> $lpos,
      ],
    ];
  }
};

class Inv extends Blade {
  protected $inv; // Lim
  
  function __construct(Lim $inv) { $this->inv = $inv; }
  function right(): ?Face { return $this->inv->left(); }
  function left(): ?Face { return $this->inv->right(); }
  function setLeft(?Face $left): void { $this->inv->setRight($left); }
  function coords(): array { return array_reverse($this->inv->coords()); }
  function setCoords(array $coords): void { $this->inv->setCoords(array_reverse($coords)); }
  function start(): array { return $this->inv->end(); }
  function setStart(array $pos): void { $this->inv->setEnd($pos); }
  function end(): array { return $this->inv->start(); }
  function setEnd(array $pos): void { $this->inv->setStart($pos); }
  function inv(): Blade { return $this->inv; }
  function fiNum(): int { return $this->inv->fiOfInv(); }
  function setFiNum(int $num): void { $this->inv->setFiOfInv($num); }
};

if (php_sapi_name()<>'cli') echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>simplif</title></head><body><pre>\n";
//echo "argc=$argc\n"; die();

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

if (0) { // Test
  $array = ['a','b','c','d','e'];
  foreach ($array as $no => $elt) {
    if ($no == 1)
      unset($array[3]);
    echo $elt;
  }
  die("\n");
}

// construction de la carte
$geojfilePath = __DIR__.'/limcomfr.geojson';
//$geojfilePath = __DIR__.'/limtest.geojson';
$geojfile = new GeoJFile($geojfilePath);
foreach ($geojfile->quickReadFeatures() as $feature) {
  Lim::add($feature);
}

// construction des anneaux
$errors = [];
foreach (Face::$all as $faceId => $face) {
  if (!$face->createRings())
    $errors[] = $faceId;
}
if ($errors) {
  echo count($errors)," erreurs de création d'anneaux sur :\n";
  foreach ($errors as $faceId) {
    echo Yaml::dump([$faceId=> Face::$all[$faceId]->asArray()]);
  }
  die();
}

Face::createExterior(); // crée une Face exterior sans extérieur mais avec comme trou tous les brins bordant l'extérieur
//echo Yaml::dump(['exterior'=> Face::$exterior->asArray()], 4, 2); die();
//echo Yaml::dump(['17485/u'=> Face::get('17485/u')->areaHa()]);
//echo Yaml::dump(['exterior'=> Face::get('exterior')->areaHa()]);

if ($argc >= 2) {
  if ($argv[1] == 'faces') { // affichage des faces 
    foreach (Face::$all as $id => $face)
      $faces[$id] = $face->asArray();
    ksort($faces);
    echo Yaml::dump(['Faces'=> $faces], 4, 2);
    die();
  }
  elseif ($argv[1] == 'face') { // affichage d'une face
    //print_r(Face::$all[$argv[2]]);
    echo Yaml::dump(['Faces'=> [$argv[2] => Face::$all[$argv[2]]->asArray()]], 4, 2);
    die();
  }
  elseif ($argv[1]= 'lim') {
    echo Yaml::dump(['Lims'=> [$argv[2] => Lim::$all[$argv[2]]->geojson()]], 4, 2);
    die();
  }
}

// Première étape - concaténer les brins distincts séparés par un noeud connectant exactement ces 2 brins
if (1) {
  foreach (Lim::$all as $num => $lim) {
    if (!$lim->coords()) {
      //echo "La limite $num a déjà été détruite\n";
      continue;
    }
    if ($lim->fi() === $lim) {
      //echo "La limite $num est une boucle\n";
      continue;
    }
    echo "appel sur la limite $num\n";
    //echo '$lim='; print_r($lim);
    //echo '$lim->fi()='; print_r($lim->fi());
    //echo '$lim->fi()->inv()='; print_r($lim->fi()->inv());
    if ($lim->fi()->inv()->fi()->inv() == $lim) {
      echo "  Concaténation des brins $num et ",$lim->fiNum()," dans $num\n";
      Lim::concat($num, $lim->fiNum());
    }
  }
  foreach (Lim::$all as $num => $lim) {
    if (!$lim->coords()) continue; // limite déjà détruite
    if ($lim->fi() === $lim) continue; // boucle
    // cas où c'est le brin inverse
    $inv = $lim->inv();
    if ($inv->fi()->inv()->fi()->inv() == $inv) {
      echo "  Concaténation des brins -$num et ",$inv->fiNum()," dans -$num\n";
      Inv::concat(-$num, $inv->fiNum());
    }
  }
}

//echo 'Faces='; print_r(Face::$all);
//echo 'Lims='; print_r(Lim::$all);

foreach (Face::$all as $faceId => $face)
  echo Yaml::dump([$faceId => $face->asArray()]);
foreach (Lim::$all as $num => $lim)
  echo Yaml::dump([$num => $lim->asArray()]);

die();

/*
Deuxième étape - Supprimer les petits polygones dans le cas de commune MultiPolygon
Le plus petit 17306/0 fait environ 0.053 ha !
La spec fixe un seuil de 0,8 km2 soit 80 ha
Je supprime les polygones de moins de 80 ha en gardant toutefois au moins un polygone par commune
*/
if (1) {
  $coms = []; // [codeInsee => [id => 1]]
  foreach (Face::$all as $id => $face) {
    $cinsee = substr($id, 0, 5);
    $coms[$cinsee][$id] = 1;
    echo "calcul de la surface de $id\n";
    $areas[$id] = $face->areaHa();
  }
  asort($areas);
  echo Yaml::dump($areas); die();

  foreach ($areas as $id => $areaHa) {
    if ($areaHa >= 80) break; // on s'arrête à 80 ha
    $cinsee = substr($id, 0, 5);
    if (count($coms[$cinsee]) == 1) { // on garde les polygones seuls pour leur commune
      echo "$id de $areaHa ha conservé car seul représentant de sa commune\n";
      continue;
    }
    if (Face::$all[$id]->delete()) { // sinon suppression du polygone
      unset($coms[$cinsee][$id]); // on retire l'id de la liste des polygones d'une commune
      echo "$id de $areaHa ha supprimé\n";
    }
    else {
      echo "$id de $areaHa ha NON supprimé\n";
    }
  }
}

/*
Deuxième étape - Suppression des petites limites de moins de 200 m de long
*/
/*if (1) {
  $lengths = [];
  foreach (Lim::$all as $num => $lim) {
    $lengths[$num] = $lim->length();
  }
  asort($lengths);
  foreach ($lengths as $num => $length) {
    if ($length >= 0.2) break; // On s'arrête à 200 m
    $lim = Lim::$all[$num];
    printf("$num: {right: %s, left: %s, length: %.3f km}\n", $lim->right()->id(), $lim->left() ? $lim->left()->id() : "''", $length);
    if ($lim->delete($num))
      echo "  supprimée\n";
    else
      echo "  NON supprimée\n";
  }
}*/

// enregistrement des limites simplifiées
/*if (1) {
  $limGeoJFile = new GeoJFileW(__DIR__.'/limcomgen.geojson', 'limcomgen', [
    'modified' => date(DATE_ATOM),
    'source' => $geojfilePath,
    'description' => "Simplification topologique des limites à partir du fichier $geojfilePath",
  ]);
  foreach (Lim::$all as $num => $lim) {
    try {
      $limGeoJFile->write($lim->geojson());
    }
    catch (Exception $e) {
      printf("Erreur sur {right: %s, left: %s, length: %.3f km}\n",
        $lim->right()->id(), $lim->left() ? $lim->left()->id() : "''", $lengths[$num]);
    }
  }
  $limGeoJFile->close();
  echo "Fichier limcomgen.geojson écrit\n";
}*/
die("Fin\n");
