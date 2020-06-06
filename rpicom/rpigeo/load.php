<?php
/*PhpDoc:
name: load.php
title: load.php - chargement des données dans PostgreSql
doc: |
  Test du chargement de Rpicom comme base géo dans PostgreSql
  1) construction eadminv à partir du rpicom
  2) génération de la topologie issue de AE2020COG
  3) affectation des topogeom de eadminv
journal: |
  4/6/2020:
    - les erreurs topologiques de chargement provenaient de la réduction du nbre de chiffres significatifs dans ogr2ogr
    - rechargement après regénération des fichiers GeoJSON sans limiter le nbre de chiffres sigificatifs
      -> aucune erreur
*/
ini_set('memory_limit', '2048M');

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

class Bbox {
  protected $min=null; // le point dont les 2 coord sont min
  protected $max=null; // le point dont les 2 coord sont max
  
  function __construct(array $lnpos) { $this->bound($lnpos); }
  
  function bound(array $lnpos) { // s'agrandit des points fournis
    if (is_numeric($lnpos[0]) && is_numeric($lnpos[1])) {
      if (is_null($this->min)) {
        $this->min = $lnpos;
        $this->max = $lnpos;
      }
      else {
        $this->min = [min($this->min[0], $lnpos[0]), min($this->min[1], $lnpos[1])];
        $this->max = [max($this->max[0], $lnpos[0]), max($this->max[1], $lnpos[1])];
      }
    }
    else {
      foreach ($lnpos as $lnm1pos) {
        $this->bound($lnm1pos);
      }
    }
    return $this;
  }
  
  function ST_MakeBox2D(): string {
    return sprintf("ST_MakeBox2D(ST_Point(%f,%f),ST_Point(%f,%f))", $this->min[0], $this->min[1], $this->max[0], $this->max[1]);
  }
  
  // génère un polygone
  function asPolygon(): Geometry {
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
        default: throw new Exception("wkt() TO BE DONE type='$this->type' ligne ".__LINE__);
      }
    }
  }
  
  function bbox(): Bbox { return new Bbox($this->coordinates); }
};

if (0) { // Test lnPosToString
  Geometry::testLnPosToString();
}

$menu = new Menu([
  // [{action} => [ 'argNames' => [{argName}], 'actions'=> [{label}=> [{argValue}]] ]]
  'rpicom'=> [
    'argNames'=> [], // liste des noms des arguments en plus de action
    'actions'=> [
      "Chargement du Rpicom"=> [],
    ],
  ],
  'ae2020topo'=> [
    'argNames'=> [], // liste des noms des arguments en plus de action
    'actions'=> [
      "Création de la topologie de ae2020"=> [],
    ],
  ],
  'ae2020'=> [
    'argNames'=> [], // liste des noms des arguments en plus de action
    'actions'=> [
      "Chargement de ae2020"=> [],
    ],
  ],
  'reste'=> [
    'argNames'=> [], // liste des noms des arguments en plus de action
    'actions'=> [
      "reste"=> [],
    ],
  ],
], $argc ?? 0, $argv ?? []);

/*if (!isset($_GET['action'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load rpicom</title></head><body>\n";
  echo "<ul>\n";
  echo "<li><a href='?action=rpicom'>Chargement du Rpicom</a></li>\n";
  echo "<li><a href='?action=ae2020'>Chargement du ae2020</a></li>\n";
  die("</ul>\n");
}*/
if (!isset($_GET['action'])) { // Menu
  $menu->show();
  die();
}

if ($_GET['action'] == 'rpicom') {
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  PgSql::query('truncate public.eadminv cascade');
  $rpicomBase = new Base(__DIR__.'/../rpicom', new Criteria(['not']));
  $rpicoms = $rpicomBase->contents();
  unset($rpicomBase);
  foreach ($rpicoms as $id => $rpicom) {
    //echo Yaml::dump([$id => $rpicom], 4, 2);
    foreach (array_keys($rpicom) as $num => $fin) {
      $version = $rpicom[$fin];
      //echo Yaml::dump([$id => [$fin => $version]]);
      if (!isset($version['name'])) {
        echo "-- evt non version ; non chargée\n";
        continue;
      }
      if (strcmp($fin, '2003-01-01') < 0) {
        echo "-- fin antérieure au 1/1/2003 ; version non chargée\n";
        continue;
      }
      if (strlen($fin) > 10)
        $fin = substr($fin, 0, 10);
      if (isset(array_keys($rpicom)[$num+1])) {
        $debut = array_keys($rpicom)[$num+1];
        if (strlen($debut) > 10) {
          echo "-- debut=$debut ; version non chargée\n";
          continue;
        }
      }
      else {
        $debut = '1943-01-01';
      }
      if (isset($version['estDéléguéeDe'])) {
        $statut = 'cDéléguée';
        $crat = $version['estDéléguéeDe'];
      }
      elseif (isset($version['estAssociéeA'])) {
        $statut = 'cAssociée';
        $crat = $version['estAssociéeA'];
      }
      elseif (isset($version['estArrondissementMunicipalDe'])) {
        $statut = 'ardtMun';
        $crat = $version['estArrondissementMunicipalDe'];
      }
      else {
        $statut = 'cSimple';
        $crat = null;
      }
      $crat = $crat ? "'$crat'" : 'null';
      $finsql = ($fin == 'now') ? 'null' : "'$fin'";
      $sql = "insert into public.eadminv(cinsee, statut, crat, debut, fin) values('$id', '$statut', $crat, '$debut', $finsql);";
      echo "$sql\n";
      try {
        PgSql::query($sql);
      }
      catch (Exception $e) {
        echo Yaml::dump([$id => $rpicom, 'debut'=> $debut, 'fin'=> $fin], 3, 2);
        die("Exception ".$e->getMessage()."\n");
      }
    }
  }
  die("Fin load Rpicom\n");
}

if ($_GET['action'] == 'ae2020topo') { // Il semble qu'il faille créer la topologie avant d'effectuer les insertions pour éviter les erreurs
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load ae2020topo</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  foreach ([
    'COMS'=> '/../data/aegeofla/AE2020COG/FRA/COMMUNE_CARTO_cor1.geojson',
    'ER'=> '/../data/aegeofla/AE2020COG/FRA/ENTITE_RATTACHEE_CARTO_cor1.geojson',
  ] as $typFichier => $geojfilePath) {
    $geojfile = new GeoJFile( __DIR__.$geojfilePath);
    $noFeature = 0;
    foreach ($geojfile->quickReadFeatures() as $feature) {
      if (!in_array($feature['id'], ['2A041','59160','80713','22093','97408','22173'])) continue;
      $geom = new Geometry($feature['geometry']);
      for ($i = 1; $i <= $geom->ST_NumGeometries(); $i++) {
        $wkt = $geom->ST_GeometryN($i)->wkt();
        $sql = "select topology.TopoGeo_AddPolygon('aegeofla_topo', ST_GeomFromText('$wkt',4326), 0.00001)";
        //echo "$sql\n";
        try {
          PgSql::query($sql);
        }
        catch (Exception $e) {
          echo Yaml::dump($feature, 3, 2);
          echo "$sql\n";
          echo "Exception ".$e->getMessage()."\n\n";
        }
        $noFeature++;
        if (($noFeature % 100) == 0)
          echo "$geojfilePath/$noFeature\n";
      }
    }
  }
  die("Fin load ae2020topo\n");
}

if ($_GET['action'] == 'ae2020') { // insertion des topogeom, ss création de la topologie, génère 6 erreurs topologiques
  $start = time();
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load ae2020</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  foreach ([
    'COMS'=> '/../data/aegeofla/AE2020COG/FRA/COMMUNE_CARTO_cor1.geojson',
    'ER'=> '/../data/aegeofla/AE2020COG/FRA/ENTITE_RATTACHEE_CARTO_cor1.geojson',
  ] as $typFichier => $geojfilePath) {
    $geojfile = new GeoJFile( __DIR__.$geojfilePath);
    $noFeature = 0;
    foreach ($geojfile->quickReadFeatures() as $feature) {
      //if (!in_array(substr($feature['id'], 0, 2), ['2A','2B','97'])) continue;
      $geom = new Geometry($feature['geometry']);
      $wkt = $geom->wkt('MultiPolygon');
      //$bboxAsPolygonWkt = $geom->bbox()->asPolygon()->wkt();
      $sql = "update public.eadminv\n"
        ."set topo = topology.toTopoGeom(ST_GeomFromText('$wkt',4326), 'aegeofla_topo', 1),\n"
        ."    geom = ST_GeomFromText('$wkt', 4326)\n"
        ."where cinsee='$feature[id]' and fin is null and ".($typFichier=='COMS' ? "statut='cSimple'" : "statut<>'cSimple'");
      //echo "$sql\n";
      try {
        PgSql::query($sql);
      }
      catch (Exception $e) {
        echo Yaml::dump($feature, 3, 2);
        echo "$sql\n";
        echo "Exception ".$e->getMessage()."\n\n";
        die();
      }
      $noFeature++;
      if (($noFeature % 100) == 0)
        printf("$geojfilePath/$noFeature traités en %.1f min.\n", (time()-$start)/60);
    }
  }
  die("Fin load ae2020\n");
}

if ($_GET['action'] == 'reste') {
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load reste</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  $sql = "select * from public.eadminv where topo is null";
  $nbrecord = 0;
  foreach (PgSql::query($sql) as $record) {
    echo "cinsee=$record[cinsee], debut=$record[debut], fin=$record[fin], statut=$record[statut]\n";
    $nbrecord++;
  }
  die("Fin load reste $nbrecord enr.\n");
}

function testGeometry(array $geometry) {
  foreach($geometry['coordinates'] as $i => $lpos) {
    foreach ($lpos as $j => $pos) {
      if ($j <> 0) {
        $delta = [
          $pos[0] - $precPos[0],
          $pos[1] - $precPos[1],
        ];
        if (($delta[0]==0) && ($delta[1]==0)) {
          echo "segment $i,$j vide\n";
        }
        $len = abs($delta[0]) + abs($delta[1]);
        if (isset($min) && ($len < $min)) {
          echo "len=$len\n";
          echo Yaml::dump(['pos'=>$pos, 'precPos'=>$precPos]);
        }
        if (!isset($min))
          $min = $len;
        else
          $min = min($min, $len);
      }
      $precPos = $pos;
    }
  }
  echo "minLen=$min\n";
}

if ($_GET['action'] == 'error2A041') {
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load error2A041</title></head><body><pre>\n";
  $feature = Yaml::parseFile(__DIR__.'/feature2A041.yaml');
  testGeometry($feature['geometry']);
  die("Fin load error2A041\n");
}

