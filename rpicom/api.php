<?php
/*PhpDoc:
name: index.php
title: api.php - API d'accès au Rpicom
doc: |
  http://rpicom.geoapi.fr/{code}/{date} est transformé en http://geoapi.fr/rpicom/api.php/{code}/{date}
  http://rpicom.geoapi.fr/ -> /prod/georef/yamldoc/pub/rpicom/api.php/
  synchro par http://localhost/synchro.php?remote=http://georef.eu/
  en local http://localhost/yamldoc/pub/rpicom/api.php/
journal: |
  27/4/2020:
    - création
*/
ini_set('memory_limit', '2048M');

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/base.inc.php';
require_once __DIR__.'/rpicom.inc.php';
require_once __DIR__.'/grpmvts.inc.php';
require_once __DIR__.'/mgrpmvts.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

//print_r($_SERVER);
$params = substr($_SERVER['REQUEST_URI'], strlen($_SERVER['SCRIPT_NAME']));
//echo "params=$params\n";

if (in_array($params, ['','/'])) {
  die("Page de description de l'API\n");
}

if (preg_match('!^/([^/]+)/?$!', $params, $matches)) {
  $id = $matches[1];
  $rpicoms = new Base(__DIR__.'/rpicom', new Criteria(['not']));
  if (!isset($rpicoms->$id)) {
    header('Content-type: text/plain');
    header('HTTP/1.1 404 Not Found');
    die("$id n'existe pas dans le référentiel");
  }
  header('Content-type: application/json');
  echo json_encode(array_merge(['@id'=> $id], $rpicoms->$id), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  die();
}

if (preg_match('!^/([^/]+)/([^/]+)$!', $params, $matches)) {
  $id = $matches[1];
  $date = $matches[2];
  $rpicoms = new Base(__DIR__.'/rpicom', new Criteria(['not']));
  if (!isset($rpicoms->$id)) {
    header('Content-type: text/plain');
    header('HTTP/1.1 404 Not Found');
    die("$id n'existe pas dans le référentiel");
  }
  $int = interpolRpicom($rpicoms->$id, $date);
  header('Content-type: application/json');
  echo json_encode($int, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  
    
  die();
}

die("api.php\n");