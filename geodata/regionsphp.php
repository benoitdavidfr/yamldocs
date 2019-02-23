<?php
if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

$docuri = 'geodata/regionsphp';
$param = !isset($_SERVER['PATH_INFO']) ? '' : substr($_SERVER['PATH_INFO'], strlen('/geodata/regionsphp/'));
//echo "param=$param\n";
if (!$param) { // sans paramètre renvoie le document de documentation
  return(JsonSch::file_get_contents(__DIR__.'/regions.yaml'));
}
if (!($data = new_doc('geodata/regions')))
  die("Erreur d'ouverture de geodata/regions");
$regions = $data->extract('/regions')['data'];
//echo '<pre>$regions='; print_r($regions);

if (preg_match('!^[A-Z][A-Z][A-Z]$!', $param, $matches) && ($region = $regions->extract("/$param"))) {
  return [$param => array_merge(['_id'=> $param], $region)];
}

// Fabrique un FeatureCollection GeoJSON a partir de la YDataTable
if (!function_exists('geojson')) {
  function geojson(YDataTable $regions): array {
    $features = [];
    foreach ($regions as $region) {
      //echo '<pre>$region='; print_r($region);
      $features[] = [
        'type'=> 'Feature',
        'properties'=> [
          '_id'=> $region['_id'],
          'name'=> $region['name'],
          'cinsee'=> $region['cinsee'],
        ],
        'geometry'=> $region['geometry'],
      ];
    }
    return [
      'type'=> 'FeatureCollection',
      'features'=> $features,
    ];
  }
}

// Fabrique un objet Map pour afficher les régions
if (!function_exists('mapOfRegions')) {
  function mapOfRegions(string $docuri) {
    $map = [
      'title'=> 'carte des régions',
      'view'=> ['latlon'=> [46.5, 3], 'zoom'=> 6],
    ];
    $map['bases'] = [
      'cartesIGN'=> [
        'title'=> "Cartes IGN",
        'type'=> 'TileLayer',
        'url'=> 'https://igngp.geoapi.fr/tile.php/cartes/{z}/{x}/{y}.jpg',
        'options'=> [ 'minZoom'=> 0, 'maxZoom'=> 18, 'attribution'=> 'ign' ],
      ],
      'OSM'=> [
        'title'=> "OSM",
        'type'=> 'TileLayer',
        'url'=> 'http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
        'options'=> [ 'minZoom'=> 0, 'maxZoom'=> 18, 'attribution'=> 'osm' ],
      ],
      'orthos'=> [
        'title'=> "Ortho-images",
        'type'=> 'TileLayer',
        'url'=> 'https://igngp.geoapi.fr/tile.php/orthos/{z}/{x}/{y}.jpg',
        'options'=> [ 'minZoom'=> 0, 'maxZoom'=> 18, 'attribution'=> 'ign' ],
      ],
      'whiteimg'=> [
        'title'=> "Fond blanc",
        'type'=> 'TileLayer',
        'url'=> 'https://visu.gexplor.fr/utilityserver.php/whiteimg/{z}/{x}/{y}.jpg',
        'options'=> [ 'minZoom'=> 0, 'maxZoom'=> 21 ],
      ],
    ];
    $request_scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME']
      : ((isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS']=='on')) ? 'https' : 'http');
    $map['overlays'] = [
      'regions'=> [
        'title'=> 'regions',
        'type'=> 'UGeoJSONLayer',
        'endpoint'=> "$request_scheme://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]/$docuri/geojson",
      ],
    ];
    $map['defaultLayers'] = ['cartesIGN', 'regions'];
    return new Map($map, "$docuri/map");
  }
}

if ($param == 'geojson') {
  return [$param => geojson($regions)];
}

if ($param == 'map') {
  return ['map'=> mapOfRegions($docuri)->asArray()];
}

elseif ($param == 'display') {
  $map = mapOfRegions($docuri);
  $map->extractByUri('/display');
}

$ids = [];
foreach ($regions as $region)
  $ids[] = $region['_id'];
return [$param => [
  'errorMessage'=> "l'id $param ne correspond pas au code d'une région de métropole",
  'comment'=> "La liste des codes des régions de métropole est : ".implode(', ',$ids),
]];
