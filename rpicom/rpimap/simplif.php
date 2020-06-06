<?php
/*PhpDoc:
name: simplif.php
title: simplif.php - simplification en arrondissant les coord. à 3 décimales, soit une résolution entre 111 m et 68 m
doc: |
  On commence par construire une carte topologique à partir des limites produites par mklim.php
  Puis:
    - on concatene les limites séparées par un noeud connectant exactement ces 2 limites
    - on supprime si possible les petits polygones de moins de 80 ha des communes en possédant plusieurs
      - attention, aux petites communes, notamment 33103/u de 3 ha
    - on supprime les petites limites de moins de 210 m
    - on simplifie les limites
      - par une simplification Douglas&Peucker avec un seuil de 0.005 °
      - puis un arrondi des coords à 3 décimales soit une résolution entre 111 m et 68 m (à 52° N)
    - on enregistre les limites ou les polygones
    - correction de 33103/u à la main pour éviter la dégénération en un segment
  stats en entrée:
    106 675 limites
    3 798 590 positions
    35 112 faces
    35 144 anneaux hors extérieur
  stats en sortie:
    102 540 limites
    278 341 positions
    35 085 faces
    35 097 anneaux hors extérieur

  Le fichier des communes en GeoJSON passe de 148 Mo à 8 Mo.
  Certains polygone sont invalides.

journal: |
  19/5/2020:
    - création de topomap.inc.php par extraction de ce fichier
    - voir comment éviter les pbs signalés
  17/5/2020:
    - mise au point de l'algo de concaténation de limites
    - sortie intéressante
      - erreur sur 33103/u de 3 ha réduit à un segment corrigée de manière spécifique dans deleteSmallLim()
      - pbs sur
        - 29232
        - 29058
        - 29290
        - 29279
*/
require_once __DIR__.'/../geojfile.inc.php';
require_once __DIR__.'/../geojfilew.inc.php';
require_once __DIR__.'/../../../../geovect/gegeom/gegeom.inc.php';
//require_once __DIR__.'/../../../../geovect/geom2d/geom2d.inc.php';
//require_once __DIR__.'/../../../../geovect/geometry/geometry.inc.php';
require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/topomap.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

ini_set('memory_limit', '4G');

if (0) { // ident. de la plus petite commune
  $geojfilePath = __DIR__.'/../data/aegeofla/AE2020COG/FRA/COMMUNE_CARTO_cor1.geojson';

  function size(array $bbox): float {
    $dlon = ($bbox[2] - $bbox[0]) * cos($bbox[1]/2/pi());
    $dlat = $bbox[3] - $bbox[1];
    return sqrt($dlon * $dlon + $dlat * $dlat);
  }
  
  $geojfile = new GeoJFile($geojfilePath);
  $dlonMin = null;
  $dlatMin = null;
  $sizeMin = null;
  foreach ($geojfile->quickReadFeatures() as $feature) {
    //print_r($feature);
    //echo Yaml::dump([$feature['id'] => $feature['bbox']]);
    $bbox = $feature['bbox'];
    $dlon = $bbox[2] - $bbox[0];
    if (($dlonMin === null) || ($dlon < $dlonMin)) {
      $dlonMin = $dlon;
      $dlonMinId = $feature['id'];
    }
    $dlat = $bbox[3] - $bbox[1];
    if (($dlatMin === null) || ($dlat < $dlatMin)) {
      $dlatMin = $dlat;
      $dlatMinId = $feature['id'];
    }
    $size = size($bbox);
    if (($sizeMin === null) || ($size < $sizeMin)) {
      $sizeMin = $size;
      $sizeMinId = $feature['id'];
    }
    //$counter++;
    //if ($counter % 10000 == 0) echo "counter=$counter\n";
    //if (++$counter >= 100) break;
  }
  echo "dLonMin: $dlonMinId => $dlonMin °\n";
  echo "dLatMin: $dlatMinId => $dlatMin °\n";
  echo printf("sizeMin: $sizeMinId => %f° = %f km\n", $sizeMin, $sizeMin/360*40000);
  /*
  dLonMin: 33103 => 0.00272 °
  dLatMin: 33103 => 0.0026299999999964 °
  sizeMin: 33103 => 0.003210° = 0.356670 km
  */
}

if (php_sapi_name()<>'cli') echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>simplif</title></head><body><pre>\n";
//echo "argc=$argc\n"; die();

if (0) { // Test
  $lSegs = [
    new gegeom\Segment([0,0],[0,1]),
    new gegeom\Segment([0,1],[0,2]),
    new gegeom\Segment([0,2],[2,2]),
  ];
  print_r($lSegs);
  echo in_array(new gegeom\Segment([0,1],[0,2]), $lSegs) ? "oui\n" : "non\n";
  echo in_array(new gegeom\Segment([0,0],[0,2]), $lSegs) ? "oui\n" : "non\n";
  die("Fin test\n");
}

if (0) { // Test
  $array = ['a','b','c','d','e'];
  foreach ($array as $no => $elt) {
    if ($no == 1)
      unset($array[3]);
    echo $elt;
  }
  die("\n");
}

// Première étape - construction de la carte
// Lecture du fichier des limites
//$geojfilePath = __DIR__.'/limcomfr.geojson';
//$geojfilePath = __DIR__.'/limtest3.geojson';
$geojfilePath = __DIR__.'/limfus.geojson'; // fusion des limites des communes simples et des entités rattachées
echo "Utilisation en entrée du fichier $geojfilePath\n";
$geojfile = new GeoJFile($geojfilePath);
foreach ($geojfile->quickReadFeatures() as $feature) {
  Lim::add($feature);
}

// construction des anneaux
$errors = [];
foreach (Face::$all as $faceId => $face) {
  if (!$face->createRings())
    $errors[] = $faceId;
}
if ($errors) {
  echo count($errors)," erreurs de création d'anneaux sur :\n";
  foreach ($errors as $faceId) {
    echo Yaml::dump([$faceId=> Face::$all[$faceId]->asArray()]);
  }
  die();
}

Face::createExterior(); // crée une Face exterior sans extérieur mais avec comme trou tous les brins bordant l'extérieur
//echo Yaml::dump(['exterior'=> Face::$exterior->asArray()], 4, 2); die();
//echo Yaml::dump(['17485/u'=> Face::get('17485/u')->areaHa()]);
//echo Yaml::dump(['exterior'=> Face::get('exterior')->areaHa()]);

if ($argc >= 2) {
  if ($argv[1] == 'faces') { // affichage des faces 
    foreach (Face::$all as $id => $face)
      $faces[$id] = $face->asArray();
    ksort($faces);
    echo Yaml::dump(['Faces'=> $faces], 4, 2);
    die();
  }
  elseif ($argv[1] == 'face') { // affichage d'une face
    //print_r(Face::$all[$argv[2]]);
    echo Yaml::dump(['Faces'=> [$argv[2] => Face::$all[$argv[2]]->asArray()]], 4, 2);
    die();
  }
  elseif ($argv[1] == 'lim') {
    echo Yaml::dump(['Lims'=> [$argv[2] => Lim::$all[$argv[2]]->geojson()]], 4, 2);
    die();
  }
  elseif ($argv[1] == 'stats') {
    Lim::stats();
    die();
  }
}

// Deuxième étape - concaténer les brins distincts séparés par un noeud connectant exactement ces 2 brins
if (1) {
  foreach (Lim::$all as $num => $lim) {
    if (!$lim->coords()) {
      //echo "La limite $num a déjà été détruite\n";
      continue;
    }
    if ($lim->fi() === $lim) {
      //echo "La limite $num est une boucle\n";
      continue;
    }
    //echo "appel sur la limite $num\n";
    //echo '$lim='; print_r($lim);
    //echo '$lim->fi()='; print_r($lim->fi());
    //echo '$lim->fi()->inv()='; print_r($lim->fi()->inv());
    if ($lim->fi()->inv()->fi()->inv() == $lim) {
      //echo "  Concaténation des brins $num et ",$lim->fiNum()," dans $num\n";
      //echo "  $num: {droite: ",$lim->right()->id(),", left: ",$lim->left()->id(),"}\n";
      Lim::concat($num, $lim->fiNum());
    }
  }
  foreach (Lim::$all as $num => $lim) {
    if (!$lim->coords()) continue; // limite déjà détruite
    if ($lim->fi() === $lim) continue; // boucle
    // cas où c'est le brin inverse
    $inv = $lim->inv();
    if ($inv->fi()->inv()->fi()->inv() == $inv) {
      //echo "  Concaténation des brins -$num et ",$inv->fiNum()," dans -$num\n";
      Inv::concat(-$num, $inv->fiNum());
    }
  }
}

//echo 'Faces='; print_r(Face::$all);
//echo 'Lims='; print_r(Lim::$all);
if (0) { // Affichage de la carte 
  foreach (Face::$all as $faceId => $face)
    echo Yaml::dump([$faceId => $face->asArray()]);
  foreach (Lim::$all as $num => $lim)
    echo Yaml::dump([$num => $lim->asArray()]);
  //die();
}

/*
Troisième étape - Supprimer les petits polygones dans le cas de commune MultiPolygon
Le plus petit 17306/0 fait environ 0.053 ha !
La spec fixe un seuil de 0,8 km2 soit 80 ha
Je supprime les polygones de moins de 80 ha en gardant toutefois au moins un polygone par commune
*/
if (1) {
  $coms = []; // [codeInsee => [id => 1]] - pour compter le nbre de Faces par commune
  foreach (Face::$all as $id => $face) {
    if ($id == 'exterior') continue;
    $cinsee = substr($id, 0, 5);
    $coms[$cinsee][$id] = 1;
    //echo "calcul de la surface de $id\n";
    $areas[$id] = $face->areaHa();
  }
  asort($areas);
  //echo Yaml::dump($areas); die();

  foreach ($areas as $id => $areaHa) {
    if ($areaHa >= 80) break; // on s'arrête à 80 ha
    $cinsee = substr($id, 0, 5);
    if (count($coms[$cinsee]) == 1) { // on garde les polygones seuls pour leur commune
      echo "$id de $areaHa ha conservé car seul représentant de sa commune\n";
      continue;
    }
    if (Face::$all[$id]->delete()) { // sinon suppression du polygone
      unset($coms[$cinsee][$id]); // on retire l'id de la liste des polygones d'une commune
      echo "$id de $areaHa ha supprimé\n";
    }
    else {
      echo "$id de $areaHa ha NON supprimé\n";
    }
  }
}
//die();

// 4ème étape - Suppression des petites limites de moins de 210 m de long
if (1) {
  $lengths = [];
  foreach (Lim::$all as $num => $lim) {
    $lengths[$num] = $lim->lengthKm();
  }
  asort($lengths);
  foreach ($lengths as $num => $lengthKm) {
    if ($lengthKm >= 0.21) break; // On s'arrête à 210 m
    $lim = Lim::$all[$num];
    printf("$num: {right: %s, left: %s, length: %.3f km}\n", $lim->right()->id(), $lim->left() ? $lim->left()->id() : "''", $lengthKm);
    if ($lim->deleteSmallLim($num))
      echo "  limite supprimée\n";
    else
      echo "  limite NON supprimée\n";
  }
}

if (0) { // Affichage de la carte
  foreach (Face::$all as $faceId => $face)
    echo Yaml::dump([$faceId => $face->asArray()]);
  foreach (Lim::$all as $num => $lim)
    echo Yaml::dump([$num => $lim->asArray()]);
  //die();
}

// 5ème étape - Simplification des limites
Lim::simplify();

if (1) { // 6ème étape - enregistrement des limites simplifiées
  $limGeoJFile = new GeoJFileW(__DIR__.'/limcomgen3.geojson', 'limcomgen3', [
    'modified' => date(DATE_ATOM),
    'source' => $geojfilePath,
    'description' => "Simplification topologique des limites à partir du fichier $geojfilePath",
  ]);
  foreach (Lim::$all as $num => $lim) {
    try {
      $limGeoJFile->write($lim->geojson());
    }
    catch (Exception $e) {
      printf("%s sur {right: %s, left: %s, length: %.3f km}\n",
        $e->getMessage(), $lim->right()->id(), $lim->left() ? $lim->left()->id() : "''", $lengths[$num]);
    }
  }
  $limGeoJFile->close();
  echo "Fichier limcomgen3.geojson enregistré\n";
}
if (1) { // 6ème étape bis - enregistrement des communes simplifiées
  $coms = []; // [codeInsee => [id => 1]] - pour compter le nbre de Faces par commune
  foreach (Face::$all as $id => $face) {
    if ($id == 'exterior') continue;
    $cinsee = substr($id, 0, 5);
    $coms[$cinsee][$id] = 1;
  }
  $comGeoJFile = new GeoJFileW(__DIR__.'/comgen3.geojson', 'comgen3', [
    'modified' => date(DATE_ATOM),
    'source' => $geojfilePath,
    'description' => "Simplification topologique des communes à partir du fichier $geojfilePath",
  ]);
  foreach ($coms as $cinsee => $faces) {
    try {
      //echo "commune $cinsee\n";
      if (count($faces) == 1) {
        $id = array_keys($faces)[0];
        $feature = [
          'type'=> 'Feature',
          'id'=> $cinsee,
          'geometry'=> [
            'type'=> 'Polygon',
            'coordinates' => Face::get($id)->coords(),
          ],
        ];
      }
      else {
        $feature = [
          'type'=> 'Feature',
          'id'=> $cinsee,
          'geometry'=> [
            'type'=> 'MultiPolygon',
            'coordinates' => [],
          ],
        ];
        foreach (array_keys($faces) as $id) {
          $feature['geometry']['coordinates'][] = Face::get($id)->coords();
        }
      }
      //echo Yaml::dump([$cinsee => $feature]);
      $comGeoJFile->write($feature);
    }
    catch(Exception $e) {
      echo "Erreur ",$e->getMessage(),"\n";
    }
  }
  $comGeoJFile->close();
  echo "Fichier comgen3.geojson enregistré\n";
}
Lim::stats();
die("Fin\n");
