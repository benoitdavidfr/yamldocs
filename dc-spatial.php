<?php
// dc-spatial.php - gestion de l'URI http://id.georef.eu/dc-spatial/{ids} telle que définie dans dc-spatial-doc
// utilise les données de dc-spatial-data - 15/2/2019

if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

if (false && !isset($_SERVER['PATH_INFO'])) {
  echo '<pre>$_SERVER='; print_r($_SERVER); echo "</pre>\n";
}
// fabrication du paramètre
$docuri = 'dc-spatial';
$param = !isset($_SERVER['PATH_INFO']) ? '' : substr($_SERVER['PATH_INFO'], strlen('/dc-spatial/'));
//echo "param=$param\n";
if (!$param) { // sans paramètre renvoie le document de documentation
  return(JsonSch::file_get_contents(__DIR__.'/dc-spatial-doc.yaml'));
}
if (!($data = new_doc('dc-spatial-data')))
  die("Erreur d'ouverture de dc-spatial-data");
//echo "<pre>data="; print_r($data);
$zones = $data->extract('/elements');
$compositions = $data->extract('/composition');
//echo "<pre>zones="; print_r($zones);
//echo "<pre>zones="; print_r($zones['data']->extract("/$param"));

// fonction fabriquant la liste des rectangles correspondant à une liste d'identifiants de zones
// Si un identifiant n'est pas défini renvoie ['unknowns'=> {liste des id non définis}]
// La fonction estt récursive pour traiter les zones composées de zones élémentaires
if (!function_exists('rectsOfZones')) {
  function rectsOfZones(array $zones, array $compositions, array $zoneids): array {
    $rects = [];
    $unknowns = [];
    foreach ($zoneids as $zoneid) {
      if (preg_match('!^([A-Z][A-Z](\.ZEE)?|World)$!', $zoneid)) {
        if ($zr = $zones['data']->extract("/$zoneid")) {
          if (is_list($zr))
            $rects = array_merge($rects, $zr);
          else
            $rects[] = $zr;
        }
        elseif ($compositions && ($zinc = $compositions['data']->extract("/$zoneid"))) {
          // appel récursif pour récupérer la définition de chaque zone atomique
          //echo "<pre>zinc="; print_r($zinc);
          $result = rectsOfZones($zones, [], $zinc);
          //echo "<pre>result="; print_r($result);
          if (isset($result['unknowns']))
            $unknowns = array_merge($unknowns, $result['unknowns']);
          else
            $rects = array_merge($rects, $result);
        }
        else
          $unknowns[] = $zoneid;
      }
      else
        $unknowns[] = $zoneid;
    }
    if ($unknowns)
      return ['unknowns'=> $unknowns];
    else
      return $rects;
  }
}

// Fabrique un objet Map pour afficher les rectangles
if (!function_exists('mapOfDcSpatial')) {
  function mapOfDcSpatial(string $docuri, string $zoneids) {
    $map = [
      'title'=> 'carte '.$zoneids,
      'view'=> ['latlon'=> [30, 0], 'zoom'=> 2],
    ];
    $map['bases'] = [
      'cartesIGN'=> [
        'title'=> "Cartes IGN",
        'type'=> 'TileLayer',
        'url'=> 'https://igngp.geoapi.fr/tile.php/cartes/{z}/{x}/{y}.jpg',
        'options'=> [ 'minZoom'=> 0, 'maxZoom'=> 18, 'attribution'=> 'ign' ],
      ],
      'cartesShom'=> [
        'title'=> "Cartes Shom",
        'type'=> 'TileLayer',
        'url'=> 'https://geoapi.fr/shomgt/tile.php/gtpyr/{z}/{x}/{y}.jpg',
        'options'=> [ 'minZoom'=> 0, 'maxZoom'=> 18, 'attribution'=> 'shom' ],
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
      'dc-spatial'=> [
        'title'=> 'dc-spatial',
        'type'=> 'UGeoJSONLayer',
        'endpoint'=> "$request_scheme://$_SERVER[SERVER_NAME]$_SERVER[SCRIPT_NAME]/$docuri/$zoneids/geojson",
      ],
      'eez'=> [
        'title'=> 'ZEE',
        'type'=> 'TileLayerWMS',
        'url'=> 'http://geo.vliz.be/geoserver/MarineRegions/wms?',
        'options'=> [
          'layers'=>'eez', 'format'=>'image/png', 'transparent'=>true,
          'minZoom'=>0, 'maxZoom'=>18,
          'detectRetina'=>true, 'attribution'=>'vliz'
        ],
      ],
    ];
    $map['defaultLayers'] = ['cartesShom', 'dc-spatial'];
    return new Map($map, "$docuri/map");
  }
}

if (preg_match('!^([^/]*)$!', $param, $matches)) {
  $result = rectsOfZones($zones, $compositions, explode(',', $param));
  //echo "<pre>result="; print_r($result);

  if (isset($result['unknowns']))
    return [$param => "Erreur: identifiant(s) ".implode(',',$result['unknowns'])." inconnu(s)"];
  elseif (is_list($result) && $result)
    return [$param => $result];
  else
    return [$param => "Erreur interne : retour non prévu"];
}
elseif (preg_match('!^([^/]*)/geojson$!', $param, $matches)) {
  $zoneids = $matches[1];
  $zoom = isset($_GET['zoom']) ? $_GET['zoom'] : (isset($_POST['zoom']) ? $_POST['zoom'] : -1);
  $threshold = ($zoom <> -1) ? (2 ** -$zoom) * 5 : 0; // seuil de 5° au zoom 0
  $result = rectsOfZones($zones, $compositions, explode(',', $zoneids));
  //echo "<pre>result="; print_r($result);
  if (isset($result['unknowns']))
    return [$zoneids => ['geojson' => "Erreur: identifiant(s) ".implode(',',$result['unknowns'])." inconnu(s)"]];
  elseif (!is_list($result) && $result)
    return [$zoneids => ['geojson' => "Erreur interne : retour non prévu"]];
  else {
    $features = [];
    foreach ($result as $rect) {
      $size = min( 
          ($rect['eastlimit']-$rect['westlimit']) * cos(($rect['southlimit']+$rect['northlimit'])/2/180*pi()),
          ($rect['northlimit']-$rect['southlimit'])
      );
      $long = ($rect['westlimit'] + $rect['eastlimit']) / 2;
      foreach ([-360, 0, 360] as $delta) { // j'élargis à < -180 et > 180 jusqu'à 240
        if ((abs($long + $delta)) > 240)
          continue;
        if ($size < $threshold) // si la taille est inférieure au seuil alors simplification par un point
          $geometry = [
            'type'=> 'Point',
            'coordinates'=> [ $long + $delta, ($rect['southlimit']+$rect['northlimit'])/2 ],
          ];
        else // sinon représentation par un rectangle
          $geometry = [
            'type'=> 'Polygon',
            'coordinates'=> [[
              [$rect['westlimit'] + $delta, $rect['southlimit']],
              [$rect['westlimit'] + $delta, $rect['northlimit']],
              [$rect['eastlimit'] + $delta, $rect['northlimit']],
              [$rect['eastlimit'] + $delta, $rect['southlimit']],
              [$rect['westlimit'] + $delta, $rect['southlimit']],
            ]],
          ];
        $features[] = [
          'type'=> 'Feature',
          'properties'=> [
            'name'=> $rect['name'],
            //'size'=> $size,
            //'zoom'=> $zoom,
            //'$threshold'=> $threshold,
          ],
          'geometry'=> $geometry,
        ];
      }
    }
    return [ $zoneids => [ 'geojson' => [
      'type'=> 'FeatureCollection',
      'features'=> $features,
    ]]];
  }
}
elseif (preg_match('!^([^/]*)/map$!', $param, $matches)) {
  $zoneids = $matches[1];
  $map = mapOfDcSpatial($docuri, $zoneids);
  return [$zoneids=> ['map'=> $map->asArray()]];
}
elseif (preg_match('!^([^/]*)/display$!', $param, $matches)) {
  $zoneids = $matches[1];
  $map = mapOfDcSpatial($docuri, $zoneids);
  $map->extractByUri('/display');
}
else
  return [];
