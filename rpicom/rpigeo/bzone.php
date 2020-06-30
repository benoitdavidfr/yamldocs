<?php
/*PhpDoc:
name: bzone.php
title: bzone.php - construit une forêt de zones géographiques structurée selon leur graphe d'inclusions
screens:
doc: |
  Les zones sont les classes d'équivalence des entités (cs+er) ayant même zone géographique
  Elles sont structurées hiérarchiquement par l'inclusion géométrique

  L'algorithme pour les créer est la suivante:
    - traduction des infos Insee sous la forme de relations topologiques entre zones, soit égalité (sameAs) soit inclusion (includes)
    - construction des zones comme classes d'équivalence des sameAs et structuration avec la relation d'inclusion
    - j'associe à chacune les réfrentiel dans lequel elle est définie
    - calcul des stats

  Sur 40661 zones créées, il en reste environ 2000 définies dans aucun des réf. disponibles

  Questions:
    - faut-il produire une base sans ces 2000 zones ?
    - faut-il uniquement leur affecter un majorant ?
    - faut-il essayer de générer une zone en utilisant le diagramme de Voronoi sur les chefs-lieux ?

journal:
  28/6/2020:
    - appariement des zones du COG2020 ok
    - il reste des erreurs
    - des écarts restent entre ecomp
      stats:
          eadminv/fin=null: 37899
          COG2020: 37899
          COG2020ecomp: 615
          COG2017: 19
          COG2018: 52
          COG2003: 36
          COG2014: 6
          COG2019: 8
          COG2015: 41
          COG2016: 31
          COG2013: 3
          nbreSansRef: 1951
          count(commune): 34968
          count(erat): 2931
          count(ecomp): 414
          count(commune)+count(erat): 37899
          count(Zones): 40661
    - 40661 zones créées
      - 37899 sont des cs ou erat du COG2020
      - 615 semblent appariables avec des ecomp (à verifier)
      - 196 semblent appariables avec des entités des COG (2019-2013+2003)
      - 1951 ne sont définies nulle part

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
  echo "<a href='?action=showMultiinc'>Affiche les multi-inclusions</a><br>\n";
  echo "<a href='?action=showIncludes'>Affiche les inclusions</a><br>\n";
  echo "<a href='?action=bzone'>Construit les zones</a><br>\n";
  echo "<a href='?action=stats'>stats</a><br>\n";
  echo "<a href='?action=compareWithCog'>compareWithCog</a><br>\n";
  echo "<a href='?action=testRattachement'>testRattachement</a><br>\n";
  echo "<a href='?action=testChangeDeRattachementPour'>testChangeDeRattachementPour</a><br>\n";
  echo "<a href='?action=testChangeDeRattachementPourAvecDéléguéePropre'>testChangeDeRattachementPourAvecDéléguéePropre</a><br>\n";
  die();
}

if (isset($_GET['action']) && ($_GET['action']=='testSameAs')) {

};

if (isset($_GET['action']) && ($_GET['action']=='testRattachement')) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>testRattachement</title></head><body><pre>\n";
  $rpicoms = <<<EOT
assoc:
  1943: { statut: cSimple, crat: null, nom: associée, fin: 2000, evtfin: { sAssocieA: rchnt } }
  2000: { statut: cAssociée, crat: rchnt, nom: associée, fin: null, evtfin: null }
rchnt:
  1943: { statut: cSimple, crat: null, nom: Rattachante, fin: 2000, evtfin: { prendPourAssociées: [assoc] } }
  2000: { statut: cSimple, crat: null, nom: Rattachante, fin: null, evtfin: null }
EOT;
  $rpicoms = Yaml::parse($rpicoms);
  //echo Yaml::dump($rpicoms);
  foreach ($rpicoms as $cinsee => $rpicom) {
    foreach ($rpicom as $dCreation => $version) {
      Rpicom::add(array_merge(
        ['cinsee'=>$cinsee, 'dcreation'=> $dCreation, 'nom'=>''],
        $version,
        ['evtfin'=> $version['evtfin'] ? json_encode($version['evtfin']) : null]));
    }
  }
  echo Yaml::dump(['rpicom'=> Rpicom::allAsArray()], 3, 2);
  Rpicom::buildAllZones();
  //print_r(Zone::$all);
  echo Yaml::dump(Zone::allAsArray(), 12, 2);
  die("Fin testRattachement");
}

if (isset($_GET['action']) && ($_GET['action']=='testChangeDeRattachementPour')) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>testChangeDeRattachementPour</title></head><body><pre>\n";
  $rpicoms = <<<EOT
assoc:
  '1943-01-01': { statut: cSimple, crat: null, nom: associée, fin: 2000, evtfin: { sAssocieA: ancRt } }
  2000: { statut: cAssociée, crat: ancRt, nom: associée, fin: 2010, evtfin: { changeDeRattachementPour: nlleR } }
  2010: { statut: cAssociée, crat: nlleR, nom: associée, fin: null, evtfin: null }
ancRt:
  '1943-01-01': { statut: cSimple, crat: null, nom: ancienneRattachante, fin: 2000, evtfin: { prendPourAssociées: [assoc, nlleR] } }
  2000: { statut: cSimple, crat: null, nom: ancienneRattachante, fin: 2010, evtfin: { perdRattachementPour: nlleR } }
  2010: { statut: cAssociée, crat: nlleR, nom: ancienneRattachante, fin: null, evtfin: null }
nlleR:
  '1943-01-01': { statut: cSimple, crat: null, nom: nlleRat, fin: 2000, evtfin: { sAssocieA: ancRt } }
  2000: { statut: cAssociée, crat: ancRt, nom: nlleRat, fin: 2010, evtfin: 'Commune rattachée devient commune de rattachement' }
  2010: { statut: cSimple, crat: null, nom: nlleRat, fin: null, evtfin: null }
EOT;
  $rpicoms = Yaml::parse($rpicoms);
  //echo Yaml::dump($rpicoms);
  foreach ($rpicoms as $cinsee => $rpicom) {
    foreach ($rpicom as $dCreation => $version) {
      Rpicom::add(array_merge(
        ['cinsee'=>$cinsee, 'dcreation'=> $dCreation, 'nom'=>''],
        $version,
        ['evtfin'=> $version['evtfin'] ? json_encode($version['evtfin']) : null]));
    }
  }
  echo Yaml::dump(['rpicom'=> Rpicom::allAsArray()], 3, 2);
  Rpicom::buildAllZones();
 // echo 'Zone::$all = '; print_r(Zone::$all);
  echo Yaml::dump(Zone::allAsArray(), 12, 2);
  die("Fin testChangeDeRattachementPour");
}

if (isset($_GET['action']) && ($_GET['action']=='testChangeDeRattachementPourAvecDéléguéePropre')) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>testRattachement</title></head><body><pre>\n";
  $rpicoms = <<<EOT
dlgue:
  1943: { statut: cSimple, crat: null, nom: future déléguée, fin: 2000, evtfin: { devientDéléguéeDe: ancRt } }
  2000: { statut: cDéléguée, crat: ancRt, nom: déléguée, fin: 2010, evtfin: { changeDeRattachementPour: nlleR } }
  2010: { statut: cDéléguée, crat: nlleR, nom: déléguée, fin: null, evtfin: null }
ancRt:
  '1943-01-01': { statut: cSimple, crat: null, nom: ancienneRattachante, fin: 2000, evtfin: { délègueA: [dlgue, ancRt, nlleR] } }
  2000: { statut: cSimple, crat: null, nom: ancRt, commeDéléguée: {nom: cDéléguée}, fin: 2010, evtfin: { perdRattachementPour: nlleR } }
  2010: { statut: cAssociée, crat: nlleR, nom: ancienneRattachante, fin: null, evtfin: null }
nlleR:
  1943: { statut: cSimple, crat: null, nom: nlleRat, fin: 2000, evtfin: { devientDéléguéeDe: ancRt } }
  2000: { statut: cDéléguée, crat: ancRt, nom: nlleRat, fin: 2010, evtfin: 'Commune rattachée devient commune de rattachement' }
  2010: { statut: cSimple, crat: null, nom: nlleRat, fin: null, evtfin: null }
EOT;
  $rpicoms = Yaml::parse($rpicoms);
  //echo Yaml::dump($rpicoms);
  foreach ($rpicoms as $cinsee => $rpicom) {
    foreach ($rpicom as $dCreation => $version) {
      Rpicom::add(array_merge(
        ['cinsee'=>$cinsee, 'dcreation'=> $dCreation, 'nom'=>''],
        $version,
        ['evtfin'=> $version['evtfin'] ? json_encode($version['evtfin']) : null]));
    }
  }
  echo Yaml::dump(['rpicom'=> Rpicom::allAsArray()], 3, 2);
  Rpicom::buildAllZones();
 // echo 'Zone::$all = '; print_r(Zone::$all);
  echo Yaml::dump(Zone::allAsArray(), 12, 2);
  die("Fin testChangeDeRattachementPourAvecDéléguéePropre");
}

PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
$where = '';
//$where = "where cinsee like '17%'"; echo "where=$where\n";
Rpicom::loadFromPg($where);

if (isset($_GET['action']) && ($_GET['action']=='showRpicom')) { // affichage Rpicom
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>showRpicom</title></head><body><pre>\n";
  echo Yaml::dump(Rpicom::allAsArray());
  die("Fin showRpicom\n");
}

if (isset($_GET['action']) && ($_GET['action']=='bzone')) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>bzone</title></head><body><pre>\n";
}
Rpicom::buildAllZones();

class Stats {
  static $stats=[];
  
  static function incr(string $label) {
    if (!isset(self::$stats[$label]))
      self::$stats[$label] = 1;
    else
      self::$stats[$label]++;
  }
  
  static function set(string $label, int $val): void { self::$stats[$label] = $val; }
  
  static function get(string $label): int { return self::$stats[$label]; }
  
  static function dump() { return Yaml::dump(['stats'=> self::$stats]); }
};

if (isset($_GET['action']) && ($_GET['action']=='stats')) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>stats</title></head><body><pre>\n";
  $whereStats = str_replace('cinsee','id', $where);
  foreach ([
    'count(commune)'=> "select count(*) nbre from commune_carto $whereStats",
    'count(erat)'=> "select count(*) nbre from entite_rattachee_carto $whereStats",
    'count(ecomp)'=> "select count(*) nbre from ecomp $whereStats",
  ] as $key => $sql) {
    foreach (PgSql::query($sql) as $tuple)
      Stats::set($key, $tuple['nbre']);
  }
  Stats::set('count(commune)+count(erat)', Stats::get('count(commune)')+Stats::get('count(erat)'));
  Stats::set('count(Zones)', count(Zone::$all));
  echo Stats::dump();
  die();
}

if (isset($_GET['action']) && ($_GET['action']=='compareWithCog')) { // vérifier que les zones identifiées comme non périmées correspondent aux entités IGN-COG
  foreach (PgSql::query("select id from commune_carto") as $tuple) {
    $commune[2020]["s$tuple[id]"] = 1;
  }
  foreach (PgSql::query("select id, type from entite_rattachee_carto") as $tuple) {
    $commune[2020]["r$tuple[id]"] = 1;
  }
  ksort($commune[2020]);
  //print_r($commune[2020]);
  // comparaison Zone / $commune[2020]
  foreach (Zone::$all as $id => $zone) {
    if ($zone->ref()=='COG2020') {
      $id2020 = '';
      foreach ($zone->vids() as $vid) {
        if (!Rpicom::get($vid)->dFin())
          $id2020 = $vid;
      }
      if (!isset($commune[2020][substr($id2020, 0, 6)]))
        echo "$id/$id2020 est une zone COG2020 INSEE et n'est pas dans COG2020 IGN\n";
    }
  }
  // comparaison $commune[2020] / Zone
  foreach (array_keys($commune[2020]) as $id2020) {
    $cinsee = substr($id2020, 1);
    $v = Rpicom::$all[$cinsee]->lastVersion();
    $vid = $v->id();
    if (Zone::get($vid)->ref() <> 'COG2020')
      echo "$vid est défini dans le COG2020 et pas dans Zone\n";
  }
  die("Fin compareWithCog ok\n");
}

if (!isset($_GET['action']) || ($_GET['action']=='bzone')) {
  echo "title: Liste des zones\n";
  echo "creator: bzone.php\n";
  echo "created: ",date(DATE_ATOM),"\n";
  echo Yaml::dump(Zone::allAsArray(), 12, 2);
  die("eof:\n");
}

die("Aucune action détectée\n");