<?php
/*PhpDoc:
name: bzonwp.php
title: bzonwp.php - récupère dans comgeos et comgeos2 les coord. géo. ponctuelles des communes abrogées non définies par COG2020
doc: |
  comgeos.yaml est produit à partir de Wikipedia par wikipedia.php
  Il est complété par comgeos2.yaml pour les communes signalée dans ce script.
  Cela permet d'affecter un point à chaque commune abrogée non définie dans COG2020.
*/
require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/../../../../phplib/pgsql.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>bzonwp</title></head><body><pre>\n";

class Zone {
  static $statuts = ['cSimple'=>'s', 'cAssociée'=>'r', 'cDéléguée'=>'r', 'ardtMun'=>'r'];
  static $all=[]; // [id => Zone]
  static $alternates=[]; // [altId => prefId]
  protected $sameAs; // [Id]
  protected $parents=[]; // [Id]
  protected $contains; // [Id]
  protected $ref;
  
  static function import(string $filename) {
    foreach (Yaml::parse(file_get_contents($filename)) as $id => $zone) {
      if (!in_array($id, ['title','creator','created']))
        self::create($id, $zone, null);
    }
  }
    
  // création récursive et inscription dans static::$all et static::$alternates
  static function create(string $id, ?array $record, ?string $parent): void {
    if (isset(self::$all[$id])) {
      if ($parent)
        $zone->parents[] = $parent;
    }
    else {
      $zone = new self;
      $zone->sameAs = $record['sameAs'] ?? [];
      $zone->parents = $parent ? [$parent] : [];
      $zone->contains = [];
      if (isset($record['contains'])) {
        foreach ($record['contains'] as $sid => $szone) {
          self::create($sid, $szone, $id);
          $zone->contains[] = $sid;
        }
      }
      $zone->ref = $record['ref'] ?? null;
      self::$all[$id] = $zone;
      foreach ($zone->sameAs as $altId) {
        if (isset(self::$alternates[$altId]))
          throw new Exception("collision");
        self::$alternates[$altId] = $id;
      }
    }
  }

  static function id(string $statut, string $cinsee, string $dcreation): string {
    if (!isset(self::$statuts[$statut]))
      throw new Exception("statut $tuple[statut]");
    else
      return self::$statuts[$statut].$cinsee.'@'.$dcreation;
  }
  
  // retourne la zone correspondant à l'id ou null
  static function get(string $id): ?Zone {
    if (isset(self::$all[$id]))
      return self::$all[$id];
    elseif ($prefId = self::$alternates[$id] ?? null)
      return self::$all[$prefId];
    else
      return null;
  }
  
  static function getRef(string $id): ?string {
    if (!($zone = self::get($id)))
      throw new Exception("Erreur $id inconnu");
    return $zone->ref;
  }

  static function test() {
    PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
    $sql = "select cinsee, dcreation, fin, statut, crat, nom, evtFin from eadminv";
    foreach (PgSql::query($sql) as $tuple) {
      $tuple['id'] = self::id($tuple['statut'], $tuple['cinsee'], $tuple['dcreation']);
      $tuple['ref'] = Zone::getRef($tuple['id']);
      echo Yaml::dump([$tuple]);
    }
    die("Fin ligne ".__LINE__."\n");
  }
}
Zone::import(__DIR__.'/zones.yaml');
//print_r(Zone::$all);
//Zone::test();

class Wikipedia {
  static $coms = []; // [dept => [url => ['name='> name, 'geo'=>[lon, lat]]]]
  
  static function init() {
    self::$coms = Yaml::parse(file_get_contents(__DIR__.'/wikipedia/comgeos.yaml'));
    $coms2 = Yaml::parse(file_get_contents(__DIR__.'/wikipedia/comgeos2.yaml'));
    foreach ($coms2 as $dept => $comsdept) {
      if ($dept <> 'title')
        foreach ($comsdept as $idcom => $com)
          self::$coms[$dept][$idcom] = $com;
    }
  }
  
  static function chercheGeo(string $cinsee, string $nom): array {
    $dept = substr($cinsee, 0, 2);
    if (!isset(self::$coms["d$dept"])) {
      echo "Com $nom ($cinsee) Dept $dept non défini\n";
      return [];
    }
    foreach (self::$coms["d$dept"] as $com) {
      if (in_array($nom, $com['names'])) {
        if (isset($com['geo']))
          return $com['geo'];
        else {
          echo "Com $nom ($cinsee) trouvée SANS geo\n";
          return [];
        }
      }
    }
    echo "Com $nom ($cinsee) NON trouvée\n";
    return [];
  }
};
Wikipedia::init();

//$rpicoms = [];
// récupération des communes abrogées
PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
$sql = "select cinsee, dcreation, fin, statut, crat, nom, evtFin from eadminv "
  ."where (cinsee,dcreation) in (select cinsee, max(dcreation) from eadminv group by cinsee)"
  ."  and fin is not null";
foreach (PgSql::query($sql) as $tuple) {
  $tuple['evtfin'] = json_decode($tuple['evtfin'], true);
  if ($tuple['evtfin']=='Sort du périmètre du Rpicom') {
    continue;
  }
  elseif (is_array($tuple['evtfin']) && (count($tuple['evtfin'])==1)) {
    switch (array_keys($tuple['evtfin'])[0]) {
      case 'seDissoutDans':
      case 'seFondDans':
      case 'fusionneDans': break;
      case 'quitteLeDépartementEtPrendLeCode': continue 2;
      default: throw new Exception("evtfin=".json_encode($tuple['evtfin'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
  }
  elseif (is_array($tuple['evtfin']) && (count($tuple['evtfin'])>1)) {
    if ((array_keys($tuple['evtfin'][0])[0]=='absorbe') && (array_keys($tuple['evtfin'][1])[0]=='quitteLeDépartementEtPrendLeCode'))
      continue;
    else
      throw new Exception("evtfin=".json_encode($tuple['evtfin'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  }
  else
    throw new Exception("evtfin=".json_encode($tuple['evtfin'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  /*if (!isset($rpicoms[$tuple['cinsee']]))
    $rpicoms[$tuple['cinsee']] = $tuple;
  else
    $rpicoms[$tuple['cinsee']]['cDéléguée']['nom'] = $tuple['nom'];*/
  
  // ne prend en compte que les zones non définies dans un référentiel
  if (Zone::getRef(Zone::id($tuple['statut'], $tuple['cinsee'], $tuple['dcreation'])))
    continue;
  //echo Yaml::dump([$tuple['cinsee'] => $tuple]);

  $geo = Wikipedia::chercheGeo($tuple['cinsee'], $tuple['nom']);
}

//echo Yaml::dump($rpicoms);
die("Fin ligne ".__LINE__."\n");
