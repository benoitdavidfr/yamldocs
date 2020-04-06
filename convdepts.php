<?php
// conversion du fichier geohisto/departements.yaml en frdepartements.yaml - 5/4/2020 11:11

ini_set('memory_limit', '2048M');

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$departements = file_get_contents(__DIR__.'/geohisto/departements.yaml');
$departements = Yaml::parse($departements, Yaml::PARSE_DATETIME);
//print_r($departements);
$nbre = 0;
foreach ($departements['data'] as $cinsee => $departement1) {
  $depts = []; // les enregistrement en sortie correspondant à $cinsee
  foreach ($departement1 as $foundingDate => $departement) {
    //echo Yaml::dump(['source' => [$cinsee => [$foundingDate => $departement]]], 99, 2);
    //echo "end_datetime: ",$commune['end_datetime']->format('Y-m-d H:i'),"\n";
    $dept = [ // l'enregistrement en sortie correspondant à [$cinsee][$foundingDate]
      'name'=> $departement['name'],
      'foundingDate'=> $foundingDate,
    ];
    //echo "end_datetime: ",$commune['end_datetime']->format('Y-m-d H:i'),"\n";
    if ($departement['end_datetime']->format('Y-m-d H:i') <> '9999-12-31 23:59') {
      $dissolutionDate = $departement['end_datetime']->add(new DateInterval('PT1S'));
      //echo "dissolutionDate: ",$dissolutionDate->format('Y-m-d'),"\n";
      $dept['dissolutionDate'] = $dissolutionDate->format('Y-m-d');
      $dept['insee_code'] = $departement['insee_code'];
    }
    else {
      $dept['sameAs'] = [ "http://id.insee.fr/geo/departement/$departement[insee_code]" ];
    }
    if (isset($departement['ancestors'])) {
      $dept['ancestors'] = [];
      foreach ($departement['ancestors'] as $ancestor) {
        $dept['ancestors'][] = [
          '$ref'=> str_replace('fr:departement:', 'http://id.georef.eu/frdepartements/', $ancestor),
        ];
      }
    }
    if (isset($departement['successors'])) {
      $dept['successors'] = [];
      foreach ($departement['successors'] as $successor) {
        $dept['successors'][] = [
          '$ref'=> str_replace('fr:departement:', 'http://id.georef.eu/frdepartements/', $successor),
        ];
      }
    }
    if (isset($departement['parents'])) {
      $dept['containedInPlace'] = [];
      foreach ($departement['parents'] as $parent) {
        $dept['containedInPlace'][] = [
          '$ref'=> str_replace('fr:region:', 'http://id.georef.eu/frregions/', $parent),
        ];
      }
    }
    if (isset($departement['population']))
      $dept['populationTotale'] = $departement['population'];
    if (isset($departement['insee_modification']))
      $dept['insee_modification'] = $departement['insee_modification'];
    if (isset($departement['chef_lieu'])) {
      $dept['chefLieu'] = [];
      foreach ($departement['chef_lieu'] as $chef_lieu) {
        $dept['chefLieu'][] = [
          '$ref'=> str_replace('fr:commune:', 'http://id.georef.eu/frcommunes/', $chef_lieu)
        ];
      }
    }
    $depts[$foundingDate] = $dept;
  }
  echo Yaml::dump([$cinsee => $depts], 99, 2);
  //if (++$nbre >= 1000) die();
}
