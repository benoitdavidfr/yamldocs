<?php
/*PhpDoc:
title: sélection des geohisto/regions non périmées
*/
if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

require_once __DIR__.'/../yd.inc.php';

$result = [
  'title'=> "sélection dans geohisto/regions des régions courantes",
  'data'=> [],
];
if (!($regions = new_yamlDoc($_SESSION['store'], 'geohisto/regions')))
  die("Erreur d'ouverture de regions");
foreach ($regions->extract('/data') as $region) {
  //echo "<pre>region="; print_r($region); echo "</pre>\n";
  if (isset($region['successors']))
    continue;
  unset($region['start_datetime']);
  unset($region['end_datetime']);
  unset($region['ancestors']);
  unset($region['KEY']);
  $region['chef_lieu'] = $region['chef_lieu'][count($region['chef_lieu'])-1];
  $result['data'][] = $region;
}
return new YamlDoc($result);
