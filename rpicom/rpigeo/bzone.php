<?php
/*PhpDoc:
name: bzone.php
title: bzone.php - construit une forêt de zones géographiques structurée selon leur graphe d'inclusions
screens:
doc: |
  Les zones sont les classes d'équivalence des entités (cs+er) ayant même zone géographique
  Elles sont structurées hiérarchiquement avec les zones incluses

  Quelle stratégie de création des zones:
    - je crée toutes les zones que j'enregistre dans Zone::$all
    - j'enregistre les relations d'équivalences et d'inclusion en parcourant les entités
    - je déduis les tops de ces relations

  Le test d'égalité doit être beaucoup plus efficace (25/6)
    tableau associatif sameAs [id => stdId]


journal:
  25/6/2020:
    - la structuration des relations d'égalité entre identifiants de versions n'est pas satisfaisante car trop couteuse
  23/6/2020:
    - première version
*/
ini_set('memory_limit', '2G');
if (php_sapi_name() <> 'cli')
  set_time_limit (6*60);

require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/../../../../phplib/pgsql.inc.php';
require_once __DIR__.'/rpicom.inc.php';
require_once __DIR__.'/zone.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if ((php_sapi_name() <> 'cli') && !isset($_GET['action'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>bzone</title></head><body>\n";
  echo "<a href='?action=showRpicom'>showRpicom</a><br>\n";
  echo "<a href='?action=buildAllZones'>Construit les zones</a><br>\n";
  die();
}


PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
$where = '';
//$where = "where cinsee like '17%'"; echo "where=$where\n";
Rpicom::loadFromPg($where);

if ((php_sapi_name() <> 'cli') && ($_GET['action']=='showRpicom')) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>showRpicom</title></head><body><pre>\n";
  echo Yaml::dump(Rpicom::allAsArray());
  die();
}

if (php_sapi_name() <> 'cli')
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>bzone</title></head><body><pre>\n";
Rpicom::buildAllZones();
//echo Yaml::dump(Zone::allAsArray(), 4);
