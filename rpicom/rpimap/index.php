<?php
/*PhpDoc:
name: index.php
title: index.php - affiche dans une carte les communes corespondant à un identifiant
doc: |
journal: |
  1/5/2020:
    - 1ère version ok
*/
require_once __DIR__.'/igeojfile.inc.php';

if (!isset($_GET['id'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>map</title></head><body>\n",
    "<form><table border=1><tr>\n",
    "<td>id:<input type='text' name='id' size=8 value=",$_GET['id'] ?? '',"></td>",
    "<td><input type='submit'></td>",
    "</tr></table></form>\n",
    "<a href='igeojfile.inc.php'>gestion de l'index</a>";
  die();
}

$igeoJFile = new IndGeoJFile(__DIR__.'/../data/aegeofla/index.igf');
$bbox = $igeoJFile->bbox($_GET['id']); // variable utilisée dans le code JS pour définir la vue
?>
<!DOCTYPE HTML><html>
  <!-- carte simple utilisant les clés choisirgeoportail -->
  <head>
    <title>map</title>
    <meta charset="UTF-8">
    <!-- meta nécessaire pour le mobile -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <!-- styles nécessaires pour le mobile -->
    <link rel="stylesheet" href="https://visu.gexplor.fr/viewer.css">
    <!-- styles et src de Leaflet -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.6/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.6/dist/leaflet.js"></script>
    <!-- Include the Control.Coordinates plugin -->
    <link rel='stylesheet' href='https://benoitdavidfr.github.io/igngp/Control.Coordinates.css'>
    <script src='https://benoitdavidfr.github.io/igngp/Control.Coordinates.js'></script>
  </head>
  <body>
    <div id="map" style="height: 100%; width: 100%"></div>
    <script>
var map = L.map('map').setView(<?php echo $bbox->view(); ?>); // view pour la zone
L.control.scale({position:'bottomleft', metric:true, imperial:false}).addTo(map);

// activation du plug-in Control.Coordinates
var c = new L.Control.Coordinates();
c.addTo(map);
map.on('click', function(e) { c.setCoordinates(e); });

var wmtsurl = 'https://wxs.ign.fr/choisirgeoportail/geoportail/wmts?'
            + 'service=WMTS&version=1.0.0&request=GetTile&tilematrixSet=PM&height=256&width=256&'
            + 'tilematrix={z}&tilecol={x}&tilerow={y}';
var attrIGN = "&copy; <a href='http://www.ign.fr'>IGN</a>";

var baseLayers = {
  "Plan IGN V2" : new L.TileLayer(
      wmtsurl + '&layer=GEOGRAPHICALGRIDSYSTEMS.PLANIGNV2&format=image/png&style=normal',
      {"format":"image/png","minZoom":3,"maxZoom":18,"attribution":attrIGN}
  ),
  "Plan IGN V1" : new L.TileLayer(
      wmtsurl + '&layer=GEOGRAPHICALGRIDSYSTEMS.PLANIGN&format=image/jpeg&style=normal',
      {"format":"image/jpeg","minZoom":0,"maxZoom":18,"attribution":attrIGN}
  ),
  "ScanExpress" : new L.TileLayer(
      wmtsurl + '&layer=GEOGRAPHICALGRIDSYSTEMS.MAPS.SCAN-EXPRESS.STANDARD&format=image/jpeg&style=normal',
      {"format":"image/jpeg","minZoom":0,"maxZoom":18,"attribution":attrIGN}
  ),
  "Cartes IGN classiques" : new L.TileLayer(
      wmtsurl + '&layer=GEOGRAPHICALGRIDSYSTEMS.MAPS&format=image/jpeg&style=normal',
      {"format":"image/jpeg","minZoom":0,"maxZoom":18,"attribution":attrIGN}
  ),
  "Ortho-Photos" : new L.TileLayer(
      wmtsurl + '&layer=ORTHOIMAGERY.ORTHOPHOTOS&format=image/jpeg&style=normal',
      {"format":"image/jpeg","minZoom":0,"maxZoom":20,"attribution":attrIGN}
  ),
  "Altitude" : new L.TileLayer(
      wmtsurl + '&layer=ELEVATION.SLOPES&format=image/jpeg&style=normal',
      {"format":"image/jpeg","minZoom":6,"maxZoom":14,"attribution":attrIGN}
  )
};

var popup = function (layer) {
  return layer.feature.properties.description;
};

<?php
foreach ($igeoJFile->layers($_GET['id']) as $name => $layer) {
  //echo "$name : new L.geoJSON(",json_encode($layer),"),\n";
  $geojsonLayers[] = $name;
  echo "$name = L.geoJSON(",json_encode($layer['data']),");\n";
  echo "$name.bindPopup(popup).addTo(map);\n";
}
?>

var overlays = {
  "Parcelles cadastrales (orange)" : new L.TileLayer(
      wmtsurl + '&layer=CADASTRALPARCELS.PARCELS&format=image/png&style=bdparcellaire_o',
      {"format":"image/png","minZoom":0,"maxZoom":20,"attribution":attrIGN}
  ),
  "BD Uni j+1" : new L.TileLayer(
      wmtsurl + '&layer=GEOGRAPHICALGRIDSYSTEMS.MAPS.BDUNI.J1&format=image/png&style=normal',
      {"format":"image/png","minZoom":0,"maxZoom":18,"attribution":attrIGN}
  ),
<?php
  foreach ($geojsonLayers as $name) echo "  '$name' : $name,\n";
?>
};

map.addLayer(baseLayers["Plan IGN V2"]);

L.control.layers(baseLayers, overlays).addTo(map);
      </script>
    </body>
</html>
