<?php
ini_set('memory_limit', '2048M');

require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/../base.inc.php';
require_once __DIR__.'/../geojfile.inc.php';
require_once __DIR__.'/../menu.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Com {
  protected $id;
  protected $polygons=[];
  
  function __construct(string $id, array $geom) {
    $this->id = $id;
    if ($geom['type'] == 'MultiPolygon') {
      foreach ($geom['coordinates'] as $poly)
        $this->polygons[] = new Polygon($poly);
    }
    else
      throw new Exception("not MultiPolygon");
  }
  
  function buildTopo(string $id, array $geom): void {
    
  }
};

class Polygon {
  protected $coords;
  
  function __construct(array $coords) { $this->coords = $coords; }
};

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

if (!isset($_GET['action'])) {
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load2</title></head><body>\n";
  echo "<a href='?action=rpicom'>chargement rpicom dans eadminv</a><br>\n";
  echo "<a href='?action=topo'>construction et enregistrement de la topo</a><br>\n";
  die();
}

if ($_GET['action'] == 'rpicom') { // construction topo
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

if ($_GET['action'] == 'topo') { // construction topo
  $start = time();
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>load topo</title></head><body><pre>\n";
  PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
  $sql = "SELECT id, ST_AsGeoJSON(wkb_geometry) geom from public.commune_carto";
  foreach (PgSql::query($sql) as $tuple1) {
    echo "1: $tuple1[id], $tuple1[geom]\n";
    $com1 = new Com($tuple1['id'], json_decode($tuple1['geom'], true));
    $sql = "SELECT c2.id, ST_AsGeoJSON(c2.wkb_geometry) geom "
      ."from public.commune_carto c1, public.commune_carto c2 "
      ."where c1.id='$tuple1[id]' and c1.wkb_geometry && c2.wkb_geometry and c1.id<>c2.id";
    foreach (PgSql::query($sql) as $tuple2) {
      if (strcmp($tuple2['id'], $tuple1['id']) < 0) continue;
      echo "  2: $tuple2[id], $tuple2[geom]\n";
      $com1->buildTopo($tuple2['id'], json_decode($tuple2['geom'], true));
    }
    die("Fin\n");
  }
}