<?php
/*PhpDoc:
name: igeojfile.inc.php
title: igeojfile.inc.php - gère un index multi-fichier GeoJSON
doc: |
  Gère un fichier igf qui est constitue un index sur un identifiant dans différents fichiers GeoJSON
  Appelé comme script, fabrique l'index dans un fichier pser.
  Inclus dans un script Php fournit une classe d'utilisation de l'index en donnant le chemin du fichier pser avec un type igf.
journal: |
  1/5/2020:
    - 1ère version ok
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
  
  /*function features(string $id): array {
    $features = [];
    foreach ($this->features[$id]['files'] as $geojfileName => $position) {
      $geojfile = new GeoJFile($this->dirpath.'/'.$this->geojfiles[$geojfileName]);
      $features[] = $geojfile->quickReadOneFeature($position);
    }
    return $features;
  }*/
  
  // retourne la structure attendue par la carte
  function layers(string $id) : array {
    $layers = [];
    foreach ($this->features[$id]['files'] as $geojfileName => $position) {
      $geojfile = new GeoJFile($this->dirpath.'/'.$this->geojfiles[$geojfileName]);
      $feature = $geojfile->quickReadOneFeature($position);
      $feature['properties']['description'] =
          json_encode($feature['properties'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE| JSON_UNESCAPED_SLASHES);
      $layers[$geojfileName]['data'] = $feature;
    }
    return $layers;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;

// construction de l'index

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>igeojfile</title></head><body><pre>\n";

if (!isset($_GET['action'])) {
  echo "<a href='?action=build'>construit l'index</a>\n";
  echo "<a href='?action=show'>affiche l'index</a>\n";
  echo "<a href='?action=showOneFeature'>affiche un feature</a>\n";
  die();
}

// chemin en relatif par rapport au répertoire 
$aegeoflaroot = __DIR__.'/../data/aegeofla';

if ($_GET['action'] == 'build') {
  // liste des datasets intégrés dans l'index
  $aedatasets = [
    'AE2020COG',
    'AE2017COG',
  ];

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
  $index = []; 

  foreach ($aedatasets as $aedataset) {
    echo "$aedataset=$aedataset\n";
    $index['geojfiles'][$aedataset] = "$aedataset/FRA/COMMUNE_CARTO.geojson";
    $geojfile = new GeoJFile("$aegeoflaroot/$aedataset/FRA/COMMUNE_CARTO.geojson");
    foreach ($geojfile->quickReadFeatures() as $feature) {
      //print_r($feature);
      $id = $feature['properties']['INSEE_COM'];
      //if (substr($id, 0, 2) <> '44') continue;
      $bbox = Rect::bboxOfListOfGeoJSONPoints($feature['geometry']['coordinates'][0]);
      if (!isset($index['features'][$id])) {
        $index['features'][$id] = [
          'bbox'=> $bbox,
          'files'=> [
            $aedataset => $feature['ftell'],
          ],
        ];
      }
      else {
        $index['features'][$id]['bbox'] = $index['features'][$id]['bbox']->union($bbox);
        $index['features'][$id]['files'][$aedataset] = $feature['ftell'];
      }
      //echo '$index='; print_r($index);
      //die("Fin ligne ".__LINE__);
    }
  }
  file_put_contents("$aegeoflaroot/index.igf", serialize($index));
  echo "Fichier $aegeoflaroot/index.igf ecrit\n";
  die();
}

require_once __DIR__.'/../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if ($_GET['action'] == 'show') {
  $data = unserialize(file_get_contents("$aegeoflaroot/index.igf"));
  foreach ($data['features'] as $id => $feature) {
    $data['features'][$id]['bbox'] = $feature['bbox']->__toString();
  }
  echo Yaml::dump($data);
  die();
}

if ($_GET['action'] == 'showOneFeature') {
  $data = unserialize(file_get_contents("$aegeoflaroot/index.igf"));
  if (!isset($_GET['id'])) {
    foreach ($data['features'] as $id => $feature) {
      echo "<a href='?action=$_GET[action]&amp;id=$id'>$id</a>\n";
    }
  }
  else {
    $data['features'][$_GET['id']]['bbox'] = $data['features'][$_GET['id']]['bbox']->__toString();
    echo Yaml::dump(['id'=> $_GET['id'], 'index'=> $data['features'][$_GET['id']]], 2, 2);
    $igeofile = new IndGeoJFile("$aegeoflaroot/index.igf");
    echo Yaml::dump(['layers'=> $igeofile->layers($_GET['id'])], 5, 2);
  }
  die();
}
