<?php
/*PhpDoc:
name: load.php
title: load.php - chargements des données pour construire la base Rpigeo, version topologique sans le module topology de PostGis
screens:
doc: |
  - l'action rpicom charge le fichier rpicom dans les tables eadminv et evtCreation
  - l'action limae2020cogcom construit les limites à partir de commune_carto
  - l'action topo construit à partir de limae2020cogcom les tables edge, face, ring et eadminvgeo
  Le schéma des tables est défini dans rpigeo.sql
journal:
  6-8/6/2020:
    - première version
*/
ini_set('memory_limit', '2G');

require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/../base.inc.php';
require_once __DIR__.'/../geojfile.inc.php';
require_once __DIR__.'/../menu.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

// classe implémentant en statique les méthodes de connexion et de requete
// et générant un objet correspondant à un itérateur permettant d'accéder au résultat
class PgSql implements Iterator {
  static $server; // le nom du serveur
  protected $sql = null; // la requête conservée pour pouvoir faire plusieurs rewind
  protected $result = null; // l'objet retourné par pg_query()
  protected $first; // indique s'il s'agit du premier rewind
  protected $id; // un no en séquence à partir de 1
  protected $ctuple = false; // le tuple courant ou false
  
  static function open(string $connection_string) {
    $pattern = '!^host=([^ ]+)( port=([^ ]+))? dbname=([^ ]+) user=([^ ]+)( password=([^ ]+))?$!';
    if (!preg_match($pattern, $connection_string, $matches))
      throw new Exception("Erreur: dans PgSql::open() params \"".$connection_string."\" incorrect");
    $server = $matches[1];
    $port = $matches[3];
    $database = $matches[4];
    $user = $matches[5];
    $passwd = $matches[7] ?? null;
    self::$server = $server;
    if (!$passwd) {
      if (!is_file(__DIR__.'/secret.inc.php'))
        throw new Exception("Erreur: dans PgSql::open($connection_string), fichier secret.inc.php absent");
      else {
        $secrets = require(__DIR__.'/secret.inc.php');
        $passwd = $secrets['sql']["pgsql://$user@$server".($port ? ":$port" : '')."/"] ?? null;
        if (!$passwd)
          throw new Exception("Erreur: dans PgSql::open($connection_string), mot de passe absent de secret.inc.php");
      }
      $connection_string .= " password=$passwd";
    }
    if (!pg_connect($connection_string))
      throw new Exception('Could not connect: '.pg_last_error());
  }
  
  static function server(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans PgSql::server() server non défini");
    return self::$server;
  }
  
  static function close(): void { pg_close(); }
  
  static function query(string $sql) {
    if (!($result = @pg_query($sql)))
      throw new Exception('Query failed: '.pg_last_error());
    if ($result === TRUE)
      return TRUE;
    else
      return new PgSql($sql, $result);
  }

  function __construct(string $sql, $result) { $this->sql = $sql; $this->result = $result; $this->first = true; }
  
  function rewind(): void {
    if ($this->first) // la première fois ne pas faire de pg_query qui a déjà été fait
      $this->first = false;
    elseif (!($this->result = @pg_query($this->sql)))
      throw new Exception('Query failed: '.pg_last_error());
    $this->id = 0;
    $this->next();
  }
  
  function next(): void {
    $this->ctuple = pg_fetch_array($this->result, null, PGSQL_ASSOC);
    $this->id++;
  }
  
  function valid(): bool { return $this->ctuple <> false; }
  function current(): array { return $this->ctuple; }
  function key(): int { return $this->id; }
};

$menu = new Menu([
  // [{action} => [ 'argNames' => [{argName}], 'actions'=> [{label}=> [{argValue}]] ]]
  'rpicom'=> [
    'argNames'=> [], // liste des noms des arguments en plus de action
    'actions'=> [
      "chargement rpicom dans eadminv et evtCreation"=> [],
    ],
  ],
  'test_ae2020com'=> [
    'argNames'=> [], // liste des noms des arguments en plus de action
    'actions'=> [
      "TEST chargement des communes de AE2020COG"=> [],
    ],
  ],
  'limae2020cogcom'=> [
    'argNames'=> [], // liste des noms des arguments en plus de action
    'actions'=> [
      "chargement de limae2020cogcom"=> [],
    ],
  ],
], $argc ?? 0, $argv ?? []);

if (!isset($_GET['action'])) { // Menu
  $menu->show();
  die();
}

/*PhpDoc: screens
name: rpicom
title: chargement du fichier Yaml Rpicom dans les tables eadminv et evtCreation
*/
if ($_GET['action'] == 'rpicom') { // chargement rpicom
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load rpicom</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  PgSql::query("truncate evtCreation cascade");
  PgSql::query("truncate eadminv cascade");
  $rpicomBase = new Base(__DIR__.'/../rpicom', new Criteria(['not']));
  $rpicoms = $rpicomBase->contents();
  unset($rpicomBase);
  // Test qqs cas particuliers
  //$rpicoms = ['08377'=> $rpicoms['08377']]; // cas de disparition temporaire avant réapparition
  //$rpicoms = ['14513'=> $rpicoms['14513'], '50649'=> $rpicoms['50649']]; // cas Pont-Farcy
  foreach ($rpicoms as $id => $rpicom) {
    //echo Yaml::dump([$id => $rpicom], 4, 2);
    $rpicom_keys = array_keys($rpicom);
    foreach ($rpicom_keys as $no => $dfin) {
      if (strlen($dfin) > 10) continue; // date bis géré par ailleurs
      $version = $rpicom[$dfin];
      if (isset($version['évènementDétaillé']))
        $version['évènement'] = $version['évènementDétaillé'];
      $fin = ($dfin == 'now') ? 'null' : "'$dfin'";
      $dcreation = isset($rpicom_keys[$no+1]) ? $rpicom_keys[$no+1] : '1943-01-01';
      if (strlen($dcreation) > 10) { // cas où l'évt préc est une date bis => les 2 evts sont fusionnés
        $evt2 = $version['évènement']; // l'évt courant sera le second evt
        $version = $rpicom[$dcreation]; // je prend la version précédente
        $evt1 =  $version['évènementDétaillé'] ?? $version['évènement']; // evt préc.
        $version['évènement'] = [$evt1, $evt2]; // concaténation des 2 evts
        $dcreation = isset($rpicom_keys[$no+2]) ? $rpicom_keys[$no+2] : '1943-01-01';
      }
      list($statut, $crat) = isset($version['estAssociéeA']) ? ['cAssociée', "'$version[estAssociéeA]'"]
        : (isset($version['estDéléguéeDe']) ? ['cDéléguée', "'$version[estDéléguéeDe]'"]
          : (isset($version['estArrondissementMunicipalDe']) ? ['ardtMun', "'$version[estArrondissementMunicipalDe]'"]
            : ['cSimple', 'null']));
      if ($dfin == 'now') {
        $evtFin = 'null';
      }
      else {
        $evtFin = json_encode($version['évènement'],  JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $evtFin = "'".str_replace("'","''", $evtFin)."'";
      }
      if (isset($version['name'])) {
        $nom = str_replace("'","''", $version['name']);
        $sql = "insert into eadminv(cinsee, dcreation, fin, statut, crat, nom, evtFin) "
          ."values('$id', '$dcreation', $fin, '$statut', $crat, '$nom', $evtFin)";
      }
      else {
        $sql = "insert into evtCreation(cinsee, dcreation, evt) values('$id', '$dfin', $evtFin)";
      }
      //echo "$sql\n";
      try {
        PgSql::query($sql);
      }
      catch(Exception $e) {
        echo $e->getMessage(),"\n";
        echo "$sql\n";
        die();
      }
    }
  }
  die("Fin rpicom\n");
}

class Geometry {
  protected $type;
  protected $coordinates;
  
  function __construct(array $geom) {
    $this->type = $geom['type'];
    $this->coordinates = $geom['coordinates'];
  }
  
  // Transforme en chaine une LnPos, cad un Pos ou LPos ou LLPos ou ....
  static function lnPosToString(array $lnpos): string {
    if (is_numeric($lnpos[0]) && is_numeric($lnpos[1]))
      return "$lnpos[0] $lnpos[1]";
    $str = '';
    foreach ($lnpos as $no => $lnm1pos) {
      $str .= ($no ? ',' : '').self::lnPosToString($lnm1pos);
    }
    return "($str)";
  }

  static function testLnPosToString() { // Test lnPosToString
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>lnPosToString</title></head><body><pre>\n";
    foreach ([
      [1,1],
      [[0.3,0.345],[1.256,-1.657]],
      [[[0.3,0.345],[1.256,-1.657]],[[0,0],[1,1]]],
      [[[[0.3,0.345],[1.256,-1.657]],[[0,0],[1,1]]],[[[0.3,0.345],[1.256,-1.657]],[[0,0],[1,1]]]],
    ] as $lnPos) {
      echo Yaml::dump(['$lnPos'=> $lnPos, 'lnPosToString'=> self::lnPosToString($lnPos)]);
    }
    die("Fin Test lnPosToString\n");
  }
  
  function ST_NumGeometries(): int { // retourne le nbre de géom élémentaires
    switch ($this->type) {
      case 'Polygon': return 1;
      case 'MultiPolygon': return count($this->coordinates);
      default: throw new Exception("ST_NumGeometries() TO BE DONE type='$this->type' ligne ".__LINE__);
    }
  }
  
  function ST_GeometryN(int $n): Geometry { // retourne la nième géométrie élémentaire, à partir de 1
    switch ($this->type) {
      case 'Polygon': return $this;
      case 'MultiPolygon': return new Geometry(['type'=>'Polygon', 'coordinates'=> $this->coordinates[$n-1]]);
      default: throw new Exception("ST_GeometryN() TO BE DONE type='$this->type' ligne ".__LINE__);
    }
  }
  
  function wkt(string $force=''): string {
    if ($force == 'MultiPolygon') {
      switch ($this->type) {
        case 'Polygon': return 'MULTIPOLYGON'.self::lnPosToString([$this->coordinates]);
        case 'MultiPolygon': return 'MULTIPOLYGON'.self::lnPosToString($this->coordinates);
        default: throw new Exception("wkt() TO BE DONE type='$this->type' ligne ".__LINE__);
      }
    }
    else {
      switch ($this->type) {
        case 'Polygon': return 'POLYGON'.self::lnPosToString($this->coordinates);
        case 'MultiPolygon': return 'MULTIPOLYGON'.self::lnPosToString($this->coordinates);
        case 'LineString': return 'LINESTRING'.self::lnPosToString($this->coordinates);
        default: throw new Exception("wkt() TO BE DONE type='$this->type' ligne ".__LINE__);
      }
    }
  }
  
  function bbox(): Bbox { return new Bbox($this->coordinates); }
};

// Permet d'identifier les intervalles de positions non couverts
// Il pourrait être plus simple de gérer des intervalles de segments
class Intervals {
  protected $min; // min global des intervalles
  protected $max; // max global des intervalles
  protected $subs = []; // [min => max] - liste des intervalles couverts
  
  function __construct(int $min=null, int $max=null) { $this->min = $min; $this->max = $max; }
  
  // ajout d'un intervalle de positions couvert
  function add(string $id, int $min, int $max) {
    //if (preg_match('!(11200|34054|81121)!', $id))
      //echo "add($min, $max) sur $id\n";
    if (isset($this->subs[$min])) {
      $this->dump();
      throw new Exception("Erreur dans Intervals:add(id=$id, min=$min, max=$max)\n");
    }
    $this->subs[$min] = $max;
  }
  
  function dump(string $label='') {
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

class Com {
  //static $all=[]; // [ cinsee => Com ]
  protected $id;
  protected $polygons=[]; // [ Polygon ]
  
  function __construct(string $id, array $geom) {
    $this->id = $id;
    if ($geom['type'] <> 'MultiPolygon')
      throw new Exception("not MultiPolygon");
    foreach ($geom['coordinates'] as $noPoly => $poly)
      $this->polygons[] = new Polygon("$this->id/$noPoly", $poly);
    //self::$all[$id] = $this;
  }
  
  function buildLimits(string $id2, array $geom): void {
    if ($geom['type'] <> 'MultiPolygon')
      throw new Exception("not MultiPolygon");
    foreach ($this->polygons as $polygon)
      foreach ($geom['coordinates'] as $noPoly2 => $coords2)
        $polygon->buildLimits($this->id, "$id2/$noPoly2", $coords2);
  }
  
  function buildUnivers() {
    foreach ($this->polygons as $polygon)
      $polygon->buildUnivers();
  }
};

class Polygon {
  protected $id;
  protected $coords; // LlPos
  protected $intervals = []; // liste d'objets Intervals, 1 par ring, pour enregistrer les intervalles de pos. convertis en limites
  
  // construit une liste de segments à partir d'une liste de points ; un segment est un couple de positions
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

  function __construct(string $id, array $coords) {
    $this->id = $id; $this->coords = $coords;
    foreach ($coords as $ringno => $listOfPos)
      $this->intervals[$ringno] = new Intervals(0, count($listOfPos)-1);
  }
  
  // construit les limites entre les 2 polygones this et id2/coords2
  function buildLimits(string $id1, string $id2, array $coords2) {
    foreach ($this->coords as $noring1 => $lpos1) {
      foreach ($coords2 as $noring2 => $lpos2) {
        if ($listOfTouches = self::touches($lpos1, $lpos2)) {
          //echo Yaml::dump(['$listOfTouches'=> $listOfTouches]);
          foreach ($listOfTouches as $touches) {
            $limCoords = array_slice($lpos1, $touches[0], $touches[1]-$touches[0]+2);
            $this->writeLimit($id2, $limCoords);
            // gestion des intervalles de no de point et pas de no de segment, à REVOIR éventuellement
            $this->intervals[$noring1]->add("d $this->id/$noring1 X $id2/$noring2", $touches[0], $touches[1]+1);
          }
        }
      }
    }
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

  function buildUnivers(): void {
    //echo "exterior $this->id\n";
    foreach ($this->coords as $ringno => $lpos) {
      //$this->intervals[$ringno]->dump("$this->id/$ringno");
      $remaining = $this->intervals[$ringno]->remaining();
      if ((count($remaining) > 1) && isset($remaining[0]) && (array_values($remaining)[count($remaining)-1] == (count($lpos)-1))) {
        // cas où la fin correspond au début alors il faut concaténer les 2 limites
        //$this->intervals[$ringno]->dump("$this->id/$ringno");
        //echo Yaml::dump(['$remaining'=> $remaining]);
        $no = count($remaining)-1;
        $min = array_keys($remaining)[$no];
        $max = array_values($remaining)[$no];
        unset($remaining[$min]);
        $limCoordsFin = array_slice($lpos, $min, $max-$min+1);
        $min = array_keys($remaining)[0];
        $max = array_values($remaining)[0];
        unset($remaining[$min]);
        $limCoordsIni = array_slice($lpos, $min, $max-$min+1);
        array_pop($limCoordsFin); // point identique dans les 2 parties
        $this->writeLimit('0univers', array_merge($limCoordsFin, $limCoordsIni));
      }
      foreach ($remaining as $min => $max) {
        //echo "exterior $this->id $min $max\n";
        $limCoords = array_slice($lpos, $min, $max-$min+1);
        $this->writeLimit('0univers', $limCoords);
      }
    }
  }
  
  function writeLimit(string $id2, array $lpos) {
    static $all=[];
    $id1 = $this->id;
    if (isset($all[$id2][$id1])) // on n'enregistre pas les limites 2 fois
      return;
    $lstr = new Geometry(['type'=>'LineString', 'coordinates'=>$lpos]);
    $wkt = $lstr->wkt();
    $sql = "insert into limae2020cogcom(id1, id2, lstr) values('$id1', '$id2', ST_GeomFromText('$wkt',4326))";
    //echo "$sql\n";
    PgSql::query($sql);
    $all[$id1][$id2] = 1;
  }
};

if ($_GET['action'] == 'test_limae2020cogcom') { // test limae2020cogcom
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load test</title></head><body><pre>\n";
  $com1 = new Com('A', ['type'=> 'MultiPolygon', 'coordinates'=> [[[[0,0],[0,1],[1,1],[1,0],[0,0]]]]]);
  $com1->buildTopo('B', ['type'=> 'MultiPolygon', 'coordinates'=> [[[[1,0],[1,1],[2,0],[1,0]]]]]);
  $com1->buildTopo('C', ['type'=> 'MultiPolygon', 'coordinates'=> [[[[1,1],[0,1],[0,2],[1,1]]]]]);
  $com1->buildExterior();
  echo Yaml::dump(['limits'=> Limit::allAsArray()]);
  die("Fin\n");
}

/*PhpDoc: screens
name: limae2020cogcom
title: construction de limae2020cogcom
*/
if ($_GET['action'] == 'limae2020cogcom') { // construction limae2020cogcom
  $start = time();
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load limae2020cogcom</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  PgSql::query("truncate limae2020cogcom");
  //$where = "where id like '2A%' or id like '2B%'"; // restriction
  $where = ''; // restriction
  $sql = "select id, ST_AsGeoJSON(wkb_geometry) geom from public.commune_carto $where";
  $nbcom = 0;
  foreach (PgSql::query($sql) as $tuple1) {
    if (($nbcom % 100) == 0)
      printf("nbcom=$nbcom, time=%.1f min., memory_get_usage=%.1f Mo, memory_get_peak_usage()=%.1f Mo\n",
        (time() - $start)/60, memory_get_usage()/1024/1024, memory_get_peak_usage()/1024/1024);
    //echo "1: $tuple1[id], $tuple1[geom]\n";
    //echo "1: $tuple1[id]\n";
    $com1 = new Com($tuple1['id'], json_decode($tuple1['geom'], true));
    $sql = "select c2.id, ST_AsGeoJSON(c2.wkb_geometry) geom "
          ."from public.commune_carto c1, public.commune_carto c2 "
          ."where c1.id='$tuple1[id]' and c1.wkb_geometry && c2.wkb_geometry and c1.id<>c2.id";
    foreach (PgSql::query($sql) as $tuple2) {
      //echo "  2: $tuple2[id], $tuple2[geom]\n";
      //echo "  2: $tuple2[id]\n";
      $com1->buildLimits($tuple2['id'], json_decode($tuple2['geom'], true));
    }
    $com1->buildUnivers();
    $nbcom++;
  }
  die("Fin\n");
}

class Bbox {
  protected $min=null; // Pos
  protected $max=null; // Pos
  
  function __construct(array $lpos=[]) {
    foreach($lpos as $pos)
      $this->bound($pos);
  }
  
  function bound(array $pos): void {
    if (!$this->min) {
      $this->min = $pos;
      $this->max = $pos;
    }
    else {
      $this->min = [min($this->min[0], $pos[0]), min($this->min[1], $pos[1])];
      $this->max = [max($this->max[0], $pos[0]), max($this->max[1], $pos[1])];
    }
  }
  
  function union(Bbox $bbox): Bbox { return new Bbox([$this->min, $this->max, $bbox->min, $bbox->max]); }
 
  function asPolygon(): Geometry { // génère un polygone
    return new Geometry([
      'type'=>'Polygon',
      'coordinates'=> [[
        $this->min,
        [$this->max[0], $this->min[1]],
        $this->max,
        [$this->min[0], $this->max[1]],
        $this->min,
      ]]]);
  }
};

class Face {
  static $all;
  protected $id;
  protected $num; // numérotation à partir de 1, 1 étant la face universelle
  protected $bladeNums; // [ int ] puis [] après createRings, la liste des brins affectés à la face lors de la lecture du fichier
  protected $rings; // [ Ring ] après createRings, la face définie comme ensemble d'anneaux

  static function getOrCreate(string $id): Face { return self::$all[$id] ?? new self($id); }
  
  function __construct(string $id) {
    $this->id = $id;
    self::$all[$id] = $this;
  }
  
  function rings(): array { return $this->rings; }
  
  function addBladeNum(int $bnum): void { $this->bladeNums[] = $bnum; }
  
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

  static function numbering(): void {
    ksort(Face::$all);
    $num = 1;
    foreach (Face::$all as $face)
      $face->num = $num++;
  }
  
  function num(): int { return $this->num; }
};

class Ring {
  protected $bladeNum; // int - le num. d'un brin représentant le cycle, les autres nuM; sont déduits par Blade::fi()
  
  function __construct(array $bladeNums) {
    $this->bladeNum = $bladeNums[0];
    foreach ($bladeNums as $bladeNum) {
      if (isset($prec))
        Blade::get($prec)->setFiNum($bladeNum);
      $prec = $bladeNum;
    }
    Blade::get($prec)->setFiNum($bladeNums[0]);
  }

  function bbox(): Bbox {
    $bn = $this->bladeNum;
    $blade = Blade::get($bn);
    $bbox = $blade->bbox();
    while (true) {
      $bn = $blade->fiNum();
      if ($bn == $this->bladeNum)
        return $bbox;
      $blade = Blade::get($bn);
      $bbox = $bbox->union($blade->bbox());
    }
  }
  
  function insertSql(int $faceNum): void {
    /*
      blade int primary key, -- le brin définissant l'anneau
      face int not null references face(id), -- la face à laquelle appartient l'anneau
      bbox geometry(POLYGON, 4326) -- la boite englobante de l'anneau codée comme un polygone
    */
    $bboxWkt = $this->bbox()->asPolygon()->wkt();
    $sql = "insert into Ring(blade, face, bbox) values($this->bladeNum, $faceNum, ST_GeomFromText('$bboxWkt',4326))";
    //echo "$sql\n";
    try {
      PgSql::query($sql);
    }
    catch(Exception $e) {
      echo "sql: $sql\nException: ",$e->getMessage();
      die("Erreur ligne ".__LINE__."\n");
    }
  }
};

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
};

class Lim extends Blade {
  static $all=[]; // [ int => Lim ], utilise comme num. celui généré dans la table des limites en entrée
  protected $idRight;
  protected $idLeft;
  protected $fiNum; // Int - le brin suivant du brin dans l'anneau défini par son numéro
  protected $fiOfInv; // Int - le brin suivant du brin inverse dans l'anneau, défini par son numéro
  protected $start;
  protected $end;
  protected $bbox;
  
  static function create(int $noLim, string $idRight, string $idLeft, array $lstr): int {
    $lim = new Lim($idRight, $idLeft, $lstr);
    self::$all[$noLim] = $lim;
    return $noLim;
  }
  
  function __construct(string $idRight, string $idLeft, array $lstr) {
    $this->idRight = $idRight;
    $this->idLeft = $idLeft;
    $lpos = $lstr['coordinates'];
    $this->start = $lpos[0];
    $this->end = $lpos[count($lpos)-1];
    $this->bbox = new Bbox($lpos);
  }
  
  function inv(): Inv { return new Inv($this); }
  function start(): array { return $this->start; }
  function end(): array { return $this->end; }
  function bbox(): Bbox { return $this->bbox; }
  function setFiNum(int $fiNum): void { $this->fiNum = $fiNum; }
  function fiNum(): int { return $this->fiNum; }
  function setFiOfInv(int $fiNum): void { $this->fiOfInv = $fiNum; }
  function fiOfInv(): int { return $this->fiOfInv; }

  function insertSql(int $limNum): void {
    /*create table edge(
      id serial primary key, -- le num. de limite, utilisé comme no de brin
      rightFace int not null references face(id), -- la face à droite
      leftFace  int not null references face(id), -- la face à gauche
      nextBlade int not null, -- le brin suivant du brin positif dans son anneau, défini par un entier positif ou négatif
      prevBlade int not null, -- le brin suivant du brin inverse dans son anneau, défini par un entier positif ou négatif 
      geom  geometry(LINESTRING, 4326), -- la géométrie de la limite telle que définie dans la source IGN
      source char(10), -- source de la géométrie codée sous la forme 'AE{year}COG' ou 'AE{year}{month}' ou 'geofla{year}'
      simp3 geometry(LINESTRING, 4326)  -- la géométrie simplifiée de la limite avec une résolution de 1e-3 degrés (cad env. 100 m)
    );*/
    $rightFace = Face::$all[$this->idRight]->num();
    $leftFace = Face::$all[$this->idLeft]->num();
    $nextBlade = $this->fiNum;
    $prevBlade = $this->fiOfInv;
    $sql = "insert into edge(id, rightFace, leftFace, nextBlade, prevBlade, geom, source) "
      ."select $limNum, $rightFace, $leftFace, $nextBlade, $prevBlade, lstr, 'AE2020COG' "
      ."from limae2020cogcom where num=$limNum";
    //echo "$sql\n";
    PgSql::query($sql);
  }
};

class Inv extends Blade {
  protected $inv; // Lim
  
  function __construct(Lim $inv) { $this->inv = $inv; }
  function start(): array { return $this->inv->end(); }
  function end(): array { return $this->inv->start(); }
  function bbox(): Bbox { return $this->inv->bbox(); }
  function setFiNum(int $fiNum): void { $this->inv->setFiOfInv($fiNum); }
  function fiNum(): int { return $this->inv->fiOfInv(); }
};

if ($_GET['action'] == 'topo') { // chargement de la topologie 
  $start = time();
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load topo</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  PgSql::query("truncate edge cascade");
  PgSql::query("truncate ring cascade");
  PgSql::query("truncate eadminvgeo cascade");
  PgSql::query("truncate face cascade");
  
  // lecture des limites construites par action=limae2020cogcom
  $sql = "select num, id1, id2, ST_AsGeoJSON(lstr) lstr from limae2020cogcom";
  $nbLims = 0;
  foreach (PgSql::query($sql) as $tuple) {
    if (($nbLims % 1000) == 0)
      printf("nbLims=$nbLims, time=%.1f min., memory_get_usage=%.1f Mo, memory_get_peak_usage()=%.1f Mo\n",
        (time() - $start)/60, memory_get_usage()/1024/1024, memory_get_peak_usage()/1024/1024);
     $noLim = Lim::create($tuple['num'], $tuple['id1'], $tuple['id2'], json_decode($tuple['lstr'], true));
    Face::getOrCreate($tuple['id1'])->addBladeNum($noLim);
    Face::getOrCreate($tuple['id2'])->addBladeNum(-$noLim);
    $nbLims++;
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
  
  // numérotation des faces
  Face::numbering();
  printf("Fin numérotation des faces, time=%.1f min., memory_get_usage=%.1f Mo, memory_get_peak_usage()=%.1f Mo\n",
    (time() - $start)/60, memory_get_usage()/1024/1024, memory_get_peak_usage()/1024/1024);
  
  // Enregistrement des faces et des anneaux
  foreach (Face::$all as $face) {
    $faceNum = $face->num();
    $sql = "insert into Face(id) values($faceNum)";
    //echo "$sql\n";
    try {
      PgSql::query($sql);
    }
    catch(Exception $e) {
      echo "sql: $sql\nException: ",$e->getMessage();
      die("Erreur ligne ".__LINE__."\n");
    }
    foreach ($face->rings() as $ring) {
      $ring->insertSql($faceNum);
    }
  }
  printf("Fin enregistrement des faces, time=%.1f min., memory_get_usage=%.1f Mo, memory_get_peak_usage()=%.1f Mo\n",
    (time() - $start)/60, memory_get_usage()/1024/1024, memory_get_peak_usage()/1024/1024);

  // Enregistrement des limites
  foreach (Lim::$all as $limNum => $lim) {
    $lim->insertSql($limNum);
  }
  printf("Fin enregistrement des limites, time=%.1f min., memory_get_usage=%.1f Mo, memory_get_peak_usage()=%.1f Mo\n",
    (time() - $start)/60, memory_get_usage()/1024/1024, memory_get_peak_usage()/1024/1024);
  
  // Enregistrement de eadminvgeo
  /*create table eadminvgeo(
    cinsee char(5) not null, -- code INSEE
    dcreation date not null, -- date de création
    face int not null, -- references face(id),
    foreign key (cinsee, dcreation) references eadminv (cinsee, dcreation)
  );*/
  foreach (Face::$all as $id => $face) {
    if ($id == '0univers') continue;
    $faceNum = $face->num();
    $cinsee = substr($id, 0, 5);
    $sql = "insert into eadminvgeo(cinsee, dcreation, face) "
      ."select cinsee, dcreation, $faceNum from eadminv where cinsee='$cinsee' and fin is null";
    //echo "$sql\n";
    PgSql::query($sql);
  }
  
  die("Fin\n");
}