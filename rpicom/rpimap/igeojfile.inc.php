<?php
/*PhpDoc:
name: igeojfile.inc.php
title: igeojfile.inc.php - gère un index multi-fichiers GeoJSON
doc: |
  Gère un fichier igf qui est constitue un index sur un identifiant dans différents fichiers GeoJSON
  Appelé comme script, fabrique l'index dans un fichier pser.
  Inclus dans un script Php fournit une classe d'utilisation de l'index en donnant le chemin du fichier pser avec un type igf.
journal: |
  1/5/2020:
    - 1ère version ok
    - ajout index des noms de commune
*/
ini_set('memory_limit', '2048M');

require_once __DIR__.'/../rect.inc.php';
require_once __DIR__.'/../geojfile.inc.php';

class IndGeoJFile {
  protected $dirpath; // chemin du répertoire contenant l'index
  protected $geojfiles;
  protected $features;
  
  function __construct(string $path) {
    $this->dirpath = dirname($path);
    $data = unserialize(file_get_contents($path));
    $this->geojfiles = $data['geojfiles'];
    $this->features = $data['features'];
  }
  
  // retourne le bbox contenu dans l'index pour un id
  function bbox(string $id): Rect {
    if (!isset($this->features[$id]))
      return new Rect([41, -4, 51, 10]);
    else
      return $this->features[$id]['bbox'];
  }
  
  // liste des datasets contenant l'id
  function datasets(string $id): array {
    if (!isset($this->features[$id]))
      return [];
    else
      return array_keys($this->features[$id]['files']);
  }
  
  // Retourne la géométrie pour un dataset
  function feature(string $id, string $dataset): array {
    if (!($feature = $this->features[$id] ?? null))
      return [];
    elseif (!($offset = $feature['files'][$dataset] ?? null))
      return [];
    else {
      $geojfile = new GeoJFile($this->dirpath.'/'.$this->geojfiles[$dataset]);
      return $geojfile->quickReadOneFeature($offset);
    }
  }
  
  // retourne la structure attendue par la carte
  function layers(string $id) : array {
    $layers = [];
    foreach ($this->features[$id]['files'] as $geojfileName => $position) {
      $geojfile = new GeoJFile($this->dirpath.'/'.$this->geojfiles[$geojfileName]);
      $feature = $geojfile->quickReadOneFeature($position);
      $description = "<b>$geojfileName</b><br>\n";
      foreach ($feature['properties'] as $k => $v)
        $description .= "<i>$k</i>: $v<br>\n";
      $feature['description'] = $description;
      $layers[$geojfileName]['data'] = $feature;
    }
    return $layers;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;

require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/../menu.inc.php';
require_once __DIR__.'/datasets.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$menu = new Menu([
  // [{action} => [ 'argNames' => [{argName}], 'actions'=> [{label}=> [{argValue}]] ]]
  'build'=> [
    'argNames'=> [],
    'actions'=> [
      "construit l'index"=> [],
    ],
  ],
  'show'=> [
    'argNames'=> [],
    'actions'=> [
      "affiche l'index"=> [],
    ],
  ],
  'show1F'=> [
    'argNames'=> ['id'], // liste des noms des arguments en plus de action
    'actions'=> [
      "affiche un feature"=> ['44001'],
    ],
  ],
  'show1FwNames'=> [
    'argNames'=> ['id'], // liste des noms des arguments en plus de action
    'actions'=> [
      "affiche un feature à partir d'un index des noms par département"=> ['44'],
    ],
  ],
],
$argc ?? 0, $argv ?? []);

if (php_sapi_name() <> 'cli') { // traite le cas d'utilisation en cli, traduit les args CLI en $_GET en fonction de $menu
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>igeojfile</title></head><body><pre>\n";
}

if (!isset($_GET['action'])) { // Menu
  $menu->show();
  die();
}

// chemin en relatif par rapport au répertoire 
$aegeoflaroot = __DIR__.'/../data/aegeofla';

if ($_GET['action'] == 'build') {
  /*[ 'geojfiles'=> [
        name => path, // chemin du fichier geojson en relatif par rapport au répertoire dans lequel est stocké l'index
      ],
      'features'=> [
        id => [
          'bbox'=> rect, // Rect
          'files'=> [
            name => position,
          ]
        ],
      ],
    ]
  */
  $data = []; 

  foreach (Datasets::paths() as $dsName => $path) {
    echo Yaml::dump([$dsName=> $path]);
    $data['geojfiles'][$dsName] = "$path.geojson";
    $geojfile = new GeoJFile("$aegeoflaroot/$path.geojson");
    foreach ($geojfile->quickReadFeatures() as $feature) {
      //echo Yaml::dump($feature); die();
      $id = $feature['properties']['INSEE_COM'] ?? $feature['properties']['CODE_DEPT'].$feature['properties']['CODE_COMM'];
      //if (substr($id, 0, 2) <> '44') continue;
      //if ($id <> '86161') continue;
      $bbox = Rect::geomBbox($feature['geometry']);
      $bbox = $bbox->round(2);
      if (!isset($data['features'][$id])) {
        $data['features'][$id] = [
          'bbox'=> $bbox,
          'files'=> [ $dsName => $feature['ftell'] ],
        ];
      }
      else {
        //print_r($data['features'][$id]['bbox']->union($bbox));
        $data['features'][$id]['bbox'] = $data['features'][$id]['bbox']->union($bbox);
        $data['features'][$id]['files'][$dsName] = $feature['ftell'];
      }
      //echo '$data='; print_r($data);
      //die("Fin ligne ".__LINE__);
    }
  }
  ksort($data['features']);
  file_put_contents("$aegeoflaroot/index.igf", serialize($data));
  echo "Fichier $aegeoflaroot/index.igf ecrit\n";
  die();
}

if ($_GET['action'] == 'show') {
  $data = unserialize(file_get_contents("$aegeoflaroot/index.igf"));
  foreach ($data['features'] as $id => $feature) {
    //echo "$id -> "; print_r($data['features'][$id]);
    $data['features'][$id]['bbox'] = $feature['bbox']->__toString();
  }
  echo Yaml::dump($data);
  die();
}

if ($_GET['action'] == 'show1F') { // Affichage d'un feature particulier à partir de son id
  $data = unserialize(file_get_contents("$aegeoflaroot/index.igf"));
  if (!isset($_GET['id']) && !isset($_GET['options'])) { // liste uniq des id
    foreach ($data['features'] as $id => $fInd) {
      echo "<a href='?action=$_GET[action]&amp;id=$id'>$id</a>\n";
    }
  }
  else { // affichage du feature
    //print_r($_SERVER);
    $data['features'][$_GET['id']]['bbox'] = $data['features'][$_GET['id']]['bbox']->__toString();
    $maphref = 'http://'.$_SERVER['HTTP_HOST'].dirname($_SERVER['SCRIPT_NAME'])."/?id=$_GET[id]";
    echo "<a href='$maphref'>carte</a>\n";
    echo Yaml::dump(
      [ 'id'=> $_GET['id'],
        'index'=> $data['features'][$_GET['id']],
      ], 2, 2);
    $igeofile = new IndGeoJFile("$aegeoflaroot/index.igf");
    echo Yaml::dump(['layers'=> $igeofile->layers($_GET['id'])], 5, 2);
  }
  die();
}

if ($_GET['action'] == 'show1FwNames') { // affichage d'un index des noms
  $data = unserialize(file_get_contents("$aegeoflaroot/index.igf"));
  if (!isset($_GET['dept'])) {
    $depts = [];
    foreach ($data['features'] as $id => $fInd) {
      $dept = substr($id, 0, 2);
      if (!isset($depts[$dept]))
        echo "<a href='?action=$_GET[action]&amp;dept=$dept'>$dept</a>\n";
      $depts[$dept] = 1;
    }
  }
  else {
    set_time_limit(5*60);
    foreach ($data['features'] as $id => $fInd) {
      $dept = substr($id, 0, 2);
      if ($dept <> $_GET['dept']) continue;
      //print_r($fInd);
      $names = [];
      foreach ($fInd['files'] as $geojfname => $offset) {
        $igeofile = new IndGeoJFile("$aegeoflaroot/index.igf");
        $layers = $igeofile->layers($id);
        $prop = $layers[$geojfname]['data']['properties'];
        $names[$geojfname] = $prop['NOM_COM'] ?? $prop['NOM_COMM'];
      }
      echo "<a href='?action=show1F&amp;id=$id'>";
      echo Yaml::dump([$id => $names], 3),"</a>\n";
    }
  }
}