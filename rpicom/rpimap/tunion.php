<?php
/*PhpDoc:
name: tunion.php
title: test du calcul de l'union d'objets de AeCog
*/
// exprFonc: 21331@1973-06-01 = ((21331 + 21074) + 21268)
/*
21331:
  1983-01-01/now:
    name: Labergement-lès-Auxonne
21074:
  1983-01-01/now:
    name: Billey
21268:
  1983-01-01/now:
    end: now
    name: Flagey-lès-Auxonne
*/
require_once __DIR__.'/../geojfile.inc.php';
//require_once __DIR__.'/../../../../geovect/gegeom/gegeom.inc.php';
require_once __DIR__.'/../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$ids = ['21331','21074','21268'];

class LinearRing {
  protected $id;
  protected $index; // [ pos => index ];
  protected $coords; // liste des positions
  
  function __construct(string $id, array $coords) {
    $this->id = $id;
    if (array_pop($coords) <> $coords[0])
      throw Exception("Erreur de __construct sur $id");
    $this->coords = $coords;
    foreach ($coords as $i => $pos) {
      $label = json_encode($pos);
      if (isset($this->index[$label]))
        echo "$i -> ",$this->index[$label],"\n";
      else
        $this->index[$label] = $i;
    }
  }
  
  function coords(): array { return $this->coords; }
  
  function index(array $pos): int {
    $label = json_encode($pos);
    return $this->index[$label] ?? -1;
  }
  
  function touches(LinearRing $right): array {
    $touches = []; // [id => [start, end]]
    $coords = $this->coords;
    $coords[] = $coords[0];
    foreach ($coords as $i => $pos) {
      if (-1 <> $ind = $right->index($pos)) {
        if (!$touches) {
          if ($ind == 0)
            $ind = count($right->coords);
          $touches = [$this->id => [$i, $i], $right->id => [$ind, $ind]];
          //echo Yaml::dump($touches);
        }
        elseif (($i == $touches[$this->id][1]+1) && ($ind == $touches[$right->id][0]-1)) {
          $touches[$this->id][1] = $i;
          $touches[$right->id][0] = $ind;
          //echo "$this->id $i - $right->id $ind\n";
        }
        elseif ($touches[$this->id][0] == $touches[$this->id][0]) {
          if ($ind == 0)
            $ind = count($right->coords);
          $touches = [$this->id => [$i, $i], $right->id => [$ind, $ind]];
        }
        else
          echo "Erreur sur $this->id $i - $right->id $ind\n";
      }
    }
    return $touches;
  }
};


echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>tunion</title></head><body><pre>\n";

if (is_file(__DIR__.'/features.pser')) {
  $features = unserialize(file_get_contents(__DIR__.'/features.pser'));
}
else {
  $geojfile = new GeoJFile(__DIR__.'/../data/aegeofla/AE2020COG/FRA/COMMUNE_CARTO.geojson');
  foreach ($geojfile->quickReadFeatures() as $feature) {
    if (in_array($feature['properties']['INSEE_COM'], $ids))
      $features[$feature['properties']['INSEE_COM']] = $feature;
  }
  file_put_contents(__DIR__.'/features.pser', serialize($features));
}

foreach ($features as $id => $feature) {
  if ($feature['geometry']['type'] == 'Polygon')
    $features[$id]['linearRing'] = new LinearRing($id, $feature['geometry']['coordinates'][0]);
  //print_r($features[$id]['linearRing']);
}

foreach ($ids as $id0) {
  foreach ($ids as $id1) {
    if ($id1 == $id0) continue;
    if ($touches = $features[$id1]['linearRing']->touches($features[$id0]['linearRing']))
      echo Yaml::dump([$touches]);
  }
}
echo Yaml::dump($features);

