<?php
/*PhpDoc:
name: snap.php
title: snap.php - redéfinition de la géométrie des entités rattachées  pour la mettre en cohérence topologique avec les communes
classes:
doc: |
  La correction de la géométrie par les requêtes PostGis définies dans errorcorr.sql génère de la géomtrie incohérente topologiquement
  L'idée est snapper la géométrie des entités rattachées corrigées sur celle de commune_carto pour mettre les premières en cohérence
  avec les secondes.
journal:
  18/6/2020:
   - première version
includes:
  - pgsql.inc.php
*/

// 08173 correspond à 4 communes déléguées dont 08079

ini_set('memory_limit', '2G');

require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/pgsql.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if (!isset($_GET['action'])) {
  foreach ([
      'Pos'=> ['projOnSeg','distanceLine'],
      'LineString'=> ['distancePos','distanceSeg'],
      'Segment'=> ['inters'],
      'Geometry'=> ['ST_HausdorffDistance'],
    ] as $class => $methods) {
    foreach ($methods as $method)
      echo "<a href='?action=test&amp;class=$class&amp;method=$method'>test $class::$method()</a><br>\n";
  }
  echo "<a href='?action=snap'>snap</a><br>\n";
  die();
}

/*PhpDoc: classes
name: Geometry
title: class Geometry
methods:
*/
class Geometry {
  protected $type;
  protected $coordinates; // LPos | LLPos | LLLPos
  
  static function create(array $geom): Geometry {
    if (($geom['type'] == 'MultiPolygon') && (count($geom['coordinates'])==1))
      return self::create(['type'=> 'Polygon', 'coordinates'=> $geom['coordinates'][0]]);
    else
      return new $geom['type']($geom);
  }
  
  function __construct(array $geom) {
    $this->type = $geom['type'];
    $this->coordinates = $geom['coordinates'];
  }

  function asArray() { return ['type'=> $this->type, 'coordinates'=> $this->coordinates]; }
  
  function __toString(): string { return json_encode($this->asArray()); }
  
  function ST_HausdorffDistance(Geometry $g2): float {
    $sql = "select ST_HausdorffDistance(ST_GeomFromGeoJSON('$this'), ST_GeomFromGeoJSON('$g2')) hd";
    foreach (PgSql::query($sql) as $tuple) {
      return $tuple['hd'];
    }
  }
  static function test_ST_HausdorffDistance() {
    PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
    $ls = new LineString([[0,0],[1,0],[1,2]]);
    foreach ([
      [[0,0],[0,1]],
      [[1,0],[0,1]],
      [[0,1],[2,1]],
      [[0,1],[2,3]],
    ] as $seg) {
      echo $ls,"->ST_HausdorffDistance(",json_encode($seg),") -> ",$ls->ST_HausdorffDistance(new LineString($seg)),"\n";
    }
  }
};

/*PhpDoc: classes
name: MultiPolygon
title: class MultiPolygon extends Geometry 
methods:
*/
class MultiPolygon extends Geometry {};

/*PhpDoc: classes
name: Polygon
title: class Polygon extends Geometry
methods:
*/
class Polygon extends Geometry {
  function rings(): array {
    $array = [];
    foreach ($this->coordinates as $ring)
    $array[] = new LineString(['type'=>'LineString', 'coordinates'=>$ring]);
    return $array;
  }
};

/*PhpDoc: classes
name: LineString
title: class LineString extends Geometry
methods:
*/
class LineString extends Geometry {
  // le paramètre est soit une géométrie, soit une liste de Pos
  function __construct(array $array) {
    if (isset($array['type'])) {
      $this->type = $array['type'];
      $this->coordinates = $array['coordinates'];
    }
    elseif (isset($array[0]) && Pos::is($array[0])) {
      $this->type = 'LineString';
      $this->coordinates = $array;
    }
  }
  
  function lsegs(): array {
    $segs = [];
    foreach ($this->coordinates as $pos) {
      if (isset($prevPos))
        $segs[] = [$prevPos, $pos];
      $prevPos = $pos;
    }
    return $segs;
  }
  
  /*PhpDoc: methods
  name:  distancePos
  title: "function distancePos(array $pos): array - distance minimum de la ligne brisée à la position pos"
  doc : |
    Retourne la distance minimum entre une Pos et une ligne brisée, la pos sur la ligne correspondant à cette distance en noseg et u
    sous la forme ['dmin'=>float, 'nseg'=>int, 'u'=>float]
  */
  function distancePos(array $pos): array {
    $p0pos = Pos::diff($pos, $this->coordinates[0]);
    $result = ['dmin'=> Vect::length($p0pos), 'nseg'=>0, 'u'=>0];
    for($nseg=0; $nseg < count($this->coordinates)-1; $nseg++) {
      $a = $this->coordinates[$nseg];
      $b = $this->coordinates[$nseg+1];
      $u = Pos::projOnSeg($pos, [$a, $b]);
      // Si le point projeté est sur le segment, on considère la distance
      if (($u > 0) && ($u < 1)) {
        $distPosToSeg = abs(Pos::distanceLine($pos, $a, $b));
        if ($distPosToSeg < $result['dmin']) {
          $result = ['dmin'=> $distPosToSeg, 'nseg'=>$nseg, 'u'=>$u];
        }
      }
      $dist = Vect::length(Pos::diff($pos, $b));
      if ($dist < $result['dmin']) {
        $result = ['dmin'=> $dist, 'nseg'=> $nseg+1, 'u'=> 0];
      }
    }
    return $result;
  }
  
  static function test_distancePos(): void {
    $ls = new LineString([[0,0],[1,1],[2,0]]);
    foreach ([
      [0.1, 0],
    ] as $pos) {
      echo "${ls}->distancePos(",json_encode($pos),")=",Yaml::dump($ls->distancePos($pos), 0),"\n";
    }
  }

  // Pour tester si un segment peut être capturé par une ligne brisée calcule le max des distances des positions du segment à la ligne
  // Prend en 2ème paramètre $eps la distance de fractionnement du segment
  // Retourne aussi la localisation linéaire sur la ligne des 2 points du segment
  // sous la forme ['dmax'=>float, 'linLocs'=>[0=>['nseg'=>int, 'u'=>float], 1=>['noseg'=>int, 'u'=>float]]]
  function distanceSeg(array $seg, float $eps): array {
    $dPos = $this->distancePos($seg[0]);
    $dmax = abs($dPos['dmin']); // la distance max courante
    //echo "\n  dmax pour seg[0]=$dmax\n";
    $result = ['dmax'=> $dmax, 'linLocs'=>[0 => ['nseg'=>$dPos['nseg'], 'u'=>$dPos['u']]]]; // structuration du résultat
    $dPos = $this->distancePos($seg[1]);
    //echo "  dmax pour seg[1]=$dPos[dmin]\n";
    $dmax = max($dmax, abs($dPos['dmin']));
    $result['linLocs'][1] = ['nseg'=>$dPos['nseg'], 'u'=>$dPos['u']];
    $v = Pos::diff($seg[1], $seg[0]);
    $len = Vect::length($v);
    $imax = $len / $eps;
    $delta = $eps / $len;
    for($i=1; $i<$imax; $i++) {
      $u = $i * $delta; // distance le long du segment
      $pos = Pos::add($seg[0], Vect::scalMult($v, $u));
      $dPos = $this->distancePos($pos);
      //echo "  i=$i, u=$u, pos=",json_encode($pos)," dmax=$dPos[dmin]\n";
      if (abs($dPos['dmin']) > $dmax)
        $dmax = abs($dPos['dmin']);
    }
    $result['dmax'] = $dmax;
    return $result;
  }
  
  static function test_distanceSeg() {
    $ls = new LineString([[0,0],[1,1],[2,0]]);
    foreach ([
      [[0,0],[1,0]],
      [[1,0],[0,1]],
      [[0,0],[2,0]],
      [[-1,0],[3,0]],
    ] as $seg) {
      echo $ls,"->distanceSeg(",json_encode($seg),") -> ",Yaml::dump($ls->distanceSeg($seg, 0.1), 0),"\n";
    }
  }
};

/*PhpDoc: classes
name: Point
title: class Point extends Geometry
methods:
*/
class Point extends Geometry {};

/*PhpDoc: classes
name: Geometry
title: class Pos - porte les méthodes statiques s'appliquant aux Pos
methods:
*/
class Pos {
  // Test si le paramètre est bien une position
  static function is($pos): bool { return is_array($pos) && is_numeric($pos[0]) && is_numeric($pos[1]); }
  // p + v
  static function add(array $p, array $v): array { return [$p[0]+$v[0], $p[1]+$v[1]]; }
  // p1 - p2
  static function diff(array $p1, array $p2): array { return [$p1[0]-$p2[0], $p1[1]-$p2[1]]; }
  
  static function projOnSeg(array $pos, array $seg): float {
    {/*PhpDoc: methods
    name:  projOnSeg
    title: "static function projOnSeg(array $pos, array $seg): float - projection du point sur la droite [A,B], renvoie u"
    doc: |
      # Projection P' d'un point P sur une droite A,B
      # Les parametres sont les 3 points P, A, B
      # Renvoit u / P' = A + u * (B-A).
      # Le point projete est sur le segment ssi u est dans [0 .. 1].
      # -----------------------
      sub ProjectionPointDroite
      # -----------------------
      { my @ab = (@_[4] - @_[2], @_[5] - @_[3]); # vecteur B-A
        my @ap = (@_[0] - @_[2], @_[1] - @_[3]); # vecteur P-A
        return pscal(@ab, @ap)/(@ab[0]**2 + @ab[1]**2);
      }
    */}
    $v = Pos::diff($seg[1], $seg[0]);
    $ap = Pos::diff($pos, $seg[0]);
    return Vect::pscal($v, $ap) / Vect::pscal($v, $v);
  }
  static function test_projOnSeg() {
    foreach ([
      [[1,0], [0,0], [1,1]],
      [[1,0], [0,0], [0,2]],
      [[1,1], [0,0], [0,2]],
    ] as $lpts) {
      $p = $lpts[0];
      $a = $lpts[1];
      $b = $lpts[2];
      echo "Pos::projOnSeg([$p[0],$p[1]], [[$a[0],$a[1]], [$b[0],$b[1]]])->",Pos::projOnSeg($p, [$a, $b]),"\n";
    }
  }

  static function distanceLine(array $pos, array $a, array $b): float {
    {/*PhpDoc: methods
    name:  distanceLine
    title: "static function distanceLine(array $pos, array $a, array $b): float - distance signée de la pos à la droite définie par les 2 points a et b"
    doc: |
      La distance est positive si le point est à gauche de la droite AB et négative s'il est à droite
      # Distance signee d'un point P a une droite orientee definie par 2 points A et B
      # la distance est positive si P est a gauche de la droite AB et negative si le point est a droite
      # Les parametres sont les 3 points P, A, B
      # La fonction retourne cette distance.
      # --------------------
      sub DistancePointDroite
      # --------------------
      { my @ab = (@_[4] - @_[2], @_[5] - @_[3]); # vecteur B-A
        my @ap = (@_[0] - @_[2], @_[1] - @_[3]); # vecteur P-A
        return pvect (@ab, @ap) / Norme(@ab);
      }
    */}
    $ab = Pos::diff($b, $a);
    $ap = Pos::diff($pos, $a);
    return vect::pvect($ab, $ap) / Vect::length($ab);
  }
  static function test_distanceLine() {
    foreach ([
      [[1,0], [0,0], [1,1]],
      [[1,0], [0,0], [0,2]],
      [[0.5,0.5], [0,0], [1,1]],
    ] as $lpts) {
      $p = $lpts[0];
      $a = $lpts[1];
      $b = $lpts[2];
      echo "distanceLine(pos=[$p[0],$p[1]], a=[$a[0],$a[1]], b=[$b[0],$b[1]])->",Pos::distanceLine($p, $a, $b),"\n";
    }
  }
};


/*PhpDoc: classes
name: Vect
title: class Vect - porte les méthodes statiques s'appliquant aux Vect, cad Pos-Pos
methods:
*/
class Vect {
  static function length(array $v): float { return sqrt($v[0]*$v[0] + $v[1]*$v[1]); }
  
  /*PhpDoc: methods
  name:  pvect
  title: "static function pvect(array $a, array $b): float - produit vectoriel $a par $b"
  */
  static function pvect(array $a, array $b): float { return $a[0]*$b[1] - $a[1]*$b[0]; }
  
  /*PhpDoc: methods
  name:  pscal
  title: "static function pscal(array $a, array $b): float - produit scalaire $a par $b"
  */
  static function pscal(array $a, array $b): float { return $a[0]*$b[0] + $a[1]*$b[1]; }
  static function test_pscal() {
    foreach ([
      ['POINT(15 20)','POINT(20 15)'],
      ['POINT(1 0)','POINT(0 1)'],
      ['POINT(1 0)','POINT(0 3)'],
      ['POINT(4 0)','POINT(0 3)'],
      ['POINT(1 0)','POINT(1 0)'],
    ] as $lpts) {
      $v0 = new Point($lpts[0]);
      $v1 = new Point($lpts[1]);
      echo "($v0)->pvect($v1)=",$v0->pvect($v1),"\n";
      echo "($v0)->pscal($v1)=",$v0->pscal($v1),"\n";
    }
  }
  
  /*PhpDoc: methods
  name:  vectMult
  title: "static function vectMult(arrauy $v, float $u): array - produit d'un vecteur $v par un scalaire $u"
  */
  static function scalMult(array $v, float $u): array { return [$v[0]*$u, $v[1]*$u]; }
};


/*PhpDoc: classes
name: Segment
title: class Segment - porte les méthodes statiques s'appliquant aux Segments définis comme un array de 2 positions
methods:
*/
class Segment {
  /*PhpDoc: methods
  name:  inters
  title: "static function inters(array $s1, array $s2): array - intersection entre 2 segments"
  doc: |
    Si les segments ne s'intersectent pas alors retourne []
    S'ils s'intersectent alors retourne la pos ainsi que les abscisses u et v
    Si les 2 segments sont parallèles, alors retourne [] même s'ils sont partiellement confondus
  */
  static function inters(array $s1, array $s2): array {
    if (max($s1[0][0], $s1[1][0]) < min($s2[0][0], $s2[1][0])) return [];
    if (max($s2[0][0], $s2[1][0]) < min($s1[0][0], $s1[1][0])) return [];
    if (max($s1[0][1], $s1[1][1]) < min($s2[0][1], $s2[1][1])) return [];
    if (max($s2[0][1], $s2[1][1]) < min($s1[0][1], $s1[1][1])) return [];
    
    $v1 = Pos::diff($s1[1], $s1[0]); // vecteur correspondant au segment s1
    $v2 = Pos::diff($s2[1], $s2[0]); // vecteur correspondant au segment s2
    $v0 = Pos::diff($s2[0], $s1[0]); // vecteur s2[0] - s1[0]
    $pvect = Vect::pvect($v1, $v2);
    if ($pvect == 0)
      return []; // droites parallèles, éventuellement confondues
    $u = Vect::pvect($v0, $v2) / $pvect;
    $v = Vect::pvect($v0, $v1) / $pvect;
    if (($u >= 0) && ($u < 1) && ($v >= 0) && ($v < 1))
      return [ 'pos'=> Pos::add($s1[0], Vect::scalMult($v1, $u)), 'u'=>$u, 'v'=>$v ];
    else
      return [];
  }
  
  static function test_inters(): void {
    foreach ([
      ['s1'=> [[0,0], [10,0]], 's2'=> [[0,-5], [10,5]]],
      ['s1'=> [[0,0], [10,0]], 's2'=> [[0,0], [10,5]]],
      ['s1'=> [[0,0], [10,0]], 's2'=> [[0,-5], [10,-5]]],
      ['s1'=> [[0,0], [10,0]], 's2'=> [[0,-5], [20,0]]],
    ] as $segs) {
      $s1 = $segs['s1'];
      $s2 = $segs['s2'];
      printf ('Segment::inters([[%d,%d],[%d,%d]],[[%d,%d],[%d,%d]])->',
          $s1[0][0],$s1[0][1],$s1[1][0],$s1[1][1], $s2[0][0],$s2[0][1],$s2[1][0],$s2[1][1]);
      print_r(Segment::inters($s1, $s2));
    }
  }
};

if ($_GET['action'] == 'test') {
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>test $_GET[class]::$_GET[method]</title></head><body><pre>\n";
  $_GET['class']::{'test_'.$_GET['method']}();
  die();
}

/*PhpDoc: classes
name: Ring
title: class Ring
methods:
*/
class Ring {
  protected $bladeNum; // int - le num. d'un brin représentant le cycle, les autres num sont déduits par Blade::fi()

  function __construct(int $bladeNum) { $this->bladeNum = $bladeNum; }
  
  function asArray(): int { return $this->bladeNum; }
};

/*PhpDoc: classes
name: Face
title: class Face
methods:
*/
class Face {
  protected $label; // string - étiquette
  protected $rings=[]; // [ Ring ] - la face définie comme ensemble d'anneaux
  
  function __construct(string $label, int $limnum=0) {
    $this->label = $label;
    if ($limnum)
      $this->rings[] = new Ring($limnum);
  }
  
  function addRing(int $bladeNum) { $this->rings[] = new Ring($bladeNum); }
  
  function asArray(): array {
    $array = [];
    foreach ($this->rings as $ring)
      $array[] = $ring->asArray();
    return ["Face $this->label"=> $array];
  }
};

/*PhpDoc: classes
name: Blade
title: class Blade
methods:
*/
class Blade {};

/*PhpDoc: classes
name: Lim
title: class Lim extends Blade
methods:
*/
class Lim extends Blade {
  protected $right; // Face - face à droite
  protected $left; // Face
  protected $fiNum; // Int - le brin suivant du brin dans l'anneau défini par son numéro
  protected $fiOfInv; // Int - le brin suivant du brin inverse dans l'anneau, défini par son numéro
  protected $geom; // LineString

  function __construct(LineString $geom) {
    $this->right = null;
    $this->left = null;
    $this->fiNum = 0;
    $this->fiOfInv = 0;
    $this->geom = $geom;
  }
  
  function geom(): LineString { return $this->geom; }
  function setRight(Face $face): void { $this->right = $face; }
  function setLeft(Face $face): void { $this->left = $face; }
  function setFiNum(int $num): void { $this->fiNum = $num; }
  function setFiOfInv(int $num): void { $this->fiOfInv = $num; }
  
  function asArray(): array {
    return [
      'right'=> $this->right ? $this->right->asArray() : null,
      'left'=> $this->left ? $this->left->asArray() : null,
      'fiNum'=> $this->fiNum,
      'fiOfInv'=> $this->fiOfInv,
      'geom'=> $this->geom->asArray(),
    ];
  }
};

/*PhpDoc: classes
name: Inv
title: class Inv extends Blade
methods:
*/
class Inv extends Blade {};

/*PhpDoc: classes
name: TopoMap - carte topologique
title: class TopoMap
methods:
*/
class TopoMap {
  protected $lims=[]; // tableau des limites indexés à partir de 1
  protected $faces; // tableau des faces indexées à partir de 0, la face 0 étant la face universelle
  
  // initialisation à partir d'un polygone ou d'un MultiPolygone
  function __construct(array $geom) {
    $this->faces = [0 => new Face('Univers')]; // La face universelle
    if ($geom['type']=='Polygon') {
      $this->createFaceFromPolygon("Polygon", $geom['coordinates']);
    }
    elseif ($geom['type']=='MultiPolygon') {
      foreach($geom['coordinates'] as $nopol => $polCoords)
        $this->createFaceFromPolygon("Polygon$nopol", $polCoords);
    }
  }
  
  // création d'une face initiale
  function createFaceFromPolygon(string $label, array $polCoords) {
    foreach ($polCoords as $no => $ringCoords) {
      $limnum = $this->newLim(new LineString(['type'=> 'LineString', 'coordinates'=> $ringCoords]));
      $this->lims[$limnum]->setFiNum($limnum);
      $this->lims[$limnum]->setFiOfInv(-$limnum);
      $face = new Face("$label/Ring$no", $limnum);
      $this->lims[$limnum]->setRight($face);
      $this->faces[] = $face;
      if ($no == 0) {
        $face0 = $face;
        $this->faces[0]->addRing(-$limnum);
        $this->lims[$limnum]->setLeft($this->faces[0]);
      }
      else {
        $face0->addRing(-$limnum);
        $this->lims[$limnum]->setLeft($face0);
      }
    }
  }
  
  function newLim(LineString $coords): int {
    $limnum = count($this->lims)+1;
    $this->lims[$limnum] = new Lim($coords);
    return $limnum;
  }
  
  // ajout d'un polygone dans une des faces
  function insertPolygon(string $label, Polygon $geom) {
    foreach ($geom->rings() as $nr => $ring)
      $this->insertRing("$label/ring$nr", $ring);
  }
  
  xxxxx CA SERT A QUOI !!!!
  function insertRing(string $label, LineString $ring) {
    $lsegs = $ring->lsegs();
    foreach ($lsegs as $noseg => $seg) {
      $matches[$noseg] = $this->matchSeg($label, $noseg, $seg);
      echo Yaml::dump([$label => [$noseg=> $matches[$noseg]]]);
    }
  }
  
  // teste si le seg match une limite existante dans la carte
  function matchSeg(string $label, int $noseg, array $seg): array {
    foreach ($this->lims as $numlim => $lim) {
      $dseg = $lim->geom()->distanceSeg($seg, 1e-5);
      //echo Yaml::dump(["$label X Lim$numlim"=> ['noseg'=>$noseg, 'seg'=>$seg, 'distanceSeg'=>$dseg]]);
      if (!isset($dmin)) {
        $dmin = $dseg['dmax'];
        $result = ['numlim'=> $numlim, 'dmin'=>$dmin, 'linLocs'=> $dseg['linLocs']];
      }
      elseif ($dseg['dmax'] < $dmin) {
        $dmin = $dseg['dmax'];
        $result = ['numlim'=> $numlim, 'dmin'=>$dmin, 'linLocs'=> $dseg['linLocs']];
      }
    }
    if ($dmin < 1e-5)
      return $result;
    else
      return [];
  }
  
  function asArray(): array {
    $array = [];
    foreach ($this->lims as $no => $lim) {
      $array['lims'][$no] = $lim->asArray();
    }
    foreach ($this->faces as $no => $face) {
      $array['faces'][$no] = $face->asArray();
    }
    return $array;
  }
};


if ($_GET['action'] == 'snap') {
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>snap</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  $sql = "select ST_AsGeoJSON(wkb_geometry) geom from commune_carto where id='08173'";
  foreach (PgSql::query($sql) as $tuple) {
    $topoMap = new TopoMap(json_decode($tuple['geom'], true));
    echo yaml::dump(['TopoMap'=> $topoMap->asArray()], 4);
  }
  $sql = "select e.id, ST_AsGeoJSON(ST_Intersection(e.wkb_geometry, c.wkb_geometry)) geom "
        ."from entite_rattachee_carto e, commune_carto c "
        ."where c.id='08173' and e.insee_ratt=c.id";
  foreach (PgSql::query($sql) as $tuple) {
    $geom = Geometry::create(json_decode($tuple['geom'], true));
    echo yaml::dump([$tuple['id'] => $geom->asArray()], 3);
    $topoMap->insertPolygon($tuple['id'], $geom);
  }
  die();
}