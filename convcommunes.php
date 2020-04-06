<?php
// conversion du fichier geohisto/communes.yaml en frcommunes.yaml - 5/4/2020 11:11

ini_set('memory_limit', '2048M');

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$communes = file_get_contents(__DIR__.'/geohisto/communes.yaml');
$communes = Yaml::parse($communes, Yaml::PARSE_DATETIME);
//print_r($communes);
$nbre = 0;
foreach ($communes['data'] as $cinsee => $com1) {
  if (preg_match('!^(751\d\d|132\d\d|6938\d)$!', $cinsee)) {
    foreach ($com1 as $foundingDate => $commune) {
      fprintf(STDERR, "Suppression de $cinsee@$foundingDate -> $commune[name]\n");
    }
    continue;
  }
  $coms = []; // les enregistrement en sortie correspondant à $cinsee
  foreach ($com1 as $foundingDate => $commune) {
    //echo Yaml::dump(['source' => [$cinsee => [$foundingDate => $commune]]], 99, 2);
    //echo "end_datetime: ",$commune['end_datetime']->format('Y-m-d H:i'),"\n";
    $com = [ // l'enregistrement en sortie correspondant à [$cinsee][$foundingDate]
      'name'=> $commune['name'],
      'foundingDate'=> $foundingDate,
    ];
    //echo "end_datetime: ",$commune['end_datetime']->format('Y-m-d H:i'),"\n";
    if ($commune['end_datetime']->format('Y-m-d H:i') <> '9999-12-31 23:59') {
      $dissolutionDate = $commune['end_datetime']->add(new DateInterval('PT1S'));
      //echo "dissolutionDate: ",$dissolutionDate->format('Y-m-d'),"\n";
      $com['dissolutionDate'] = $dissolutionDate->format('Y-m-d');
      $com['insee_code'] = $commune['insee_code'];
    }
    else {
      $com['sameAs'] = [ "http://id.insee.fr/geo/commune/$commune[insee_code]" ];
    }
    if (isset($commune['ancestors'])) {
      $com['ancestors'] = [];
      foreach ($commune['ancestors'] as $ancestor) {
        $com['ancestors'][] = [
          '$ref'=> str_replace('fr:commune:', 'http://id.georef.eu/frcommunes/', $ancestor),
        ];
      }
    }
    if (isset($commune['successors'])) {
      $com['successors'] = [];
      foreach ($commune['successors'] as $successor) {
        $com['successors'][] = [
          '$ref'=> str_replace('fr:commune:', 'http://id.georef.eu/frcommunes/', $successor),
        ];
      }
    }
    $com['containedInPlace'] = [];
    $places = [];
    foreach ($commune['parents'] as $parent) {
      if (!preg_match('!^fr:departement:([^@]+)@\d\d\d\d-\d\d-\d\d$!', $parent, $matches)) {
        fprintf(STDERR, "No match on '$parent' ligne ".__LINE__."\n");
        die();
      }
      $place = 'http://id.georef.eu/frdepartements/'.$matches[1];
      if (!in_array($place, $places)) {
        if ($places) {
          fprintf(STDERR, "Erreur sur $cinsee ligne ".__LINE__."\n");
          die();
        }
        $places[] = $place;
        $com['containedInPlace'] = [ '$ref'=> $place ];
      }
    }
    if (isset($commune['population']))
      $com['populationTotale'] = $commune['population'];
    if (isset($commune['insee_modification']))
      $com['insee_modification'] = $commune['insee_modification'];
    $coms[$foundingDate] = $com;
  }
  echo Yaml::dump([$cinsee => $coms], 99, 2);
  //if (++$nbre >= 100) die();
}
