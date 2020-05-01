<?php
/*PhpDoc:
name: rpicom.inc.php
title: rpicom.inc.php - définition de fonctions
doc: |
journal: |
  28/4/2020:
    - créaton
includes:
  - base.inc.php
functions:
*/

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/base.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Rpicom {
  static function versionLaPlusRecente(array $rpicom): array { return array_values($rpicom)[0]; }
  
  static function dateLaPlusRecente(array $rpicom): string { return array_keys($rpicom)[0]; }
};

// retrouve dans la structure des versions celle qui correspond à une date donnée avec l'événement en premier champ
// si l'entité existait à cette date alors retourne un array composé d'un champ date et d'un autre version
// sinon retourne []
function interpolRpicom(array $rpicom, string $state): array {
  if (isset($rpicom['now']) && (count($rpicom)==1)) { // s'il n'y a que la version actuelle alors je la sélectionne
    return ['date'=> 'now', 'version'=> array_merge(['évènement'=> 'aucun'], $rpicom['now'])];
  }
  else { // sinon je cherche la date la plus ancienne postérieure à la date demandée
    $datevprec = null;
    foreach ($rpicom as $datev => $com) {
      //echo Yaml::dump([$id => [$datev => $com]]);
      if (strcmp($datev, $state) <= 0) { // $datev < $state
        //echo "Arrêt sur datev=$datev\n";
        break;
      }
      $datevprec = $datev;
    }
    if (!$datevprec)
      return [];
    if ($datevprec == 'now')
      return ['date'=>$datevprec, 'version'=> array_merge(['évènement'=> 'aucun'], $rpicom['now'])];
    unset($rpicom[$datevprec]['après']);
    if (EvtType::type($rpicom[$datevprec]['évènement'])['type'] == 'Création')
      return [];
    return ['date'=>$datevprec, 'version'=> $rpicom[$datevprec]];
  }
}

if (($_GET['action'] ?? null) == 'testInterpolRpicom') { // Test interpolRpicom()
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>testInterpolRpicom</title></head><body><pre>\n";
  $states = ['2020-01-01', '2015-01-01', '2010-01-01', '2009-01-01', '2003-01-01', '1990-01-01'];
  //$states = ['2015-01-01'];
  $rpicoms = new Base([
    'contents'=>[
      '22222'=> [ // a toujours existé sans changer de nom
        'now'=> [ 'name'=> "nom 22222 maintenant"],
      ],
      '33333'=> [ // a changé de nom et a toujours existé
        'now'=> [ 'name'=> "nom 33333 maintenant"],
        '2015-01-01'=> [ 'evt'=> "evt", 'name'=> "nom 33333 avant 1/1/2015"],
        '2010-01-01'=> [ 'evt'=> "evt", 'name'=> "nom 33333 jamais 1/1/2010"],
        '2010-01-01-bis'=> [ 'evt'=> "evt", 'name'=> "nom 33333 avant 1/1/2010 bis"],
        '2005-01-01'=> [ 'evt'=> "evt", 'name'=> "nom 33333 avant 1/1/2005"],
        '2000-01-01'=> [ 'evt'=> "evt", 'name'=> "nom 33333 avant 1/1/2000"],
      ],
      '44444'=> [ // n'existe que pendant un certain intervalle de temps
        '2015-01-01'=> [ 'evt'=> "evt", 'name'=> "nom 44444 avant 1/1/2015"],
        '2010-01-01'=> [ 'evt'=> "evt", 'name'=> "nom 44444 avant 1/1/2010"],
        '2005-01-01'=> [ 'evt'=> "evt", 'name'=> "nom 44444 avant 1/1/2005"],
        '2000-01-01'=> [ 'evt'=> "evt", 'name'=> "nom 44444 avant 1/1/2000"],
        '1995-01-01'=> [ 'evt'=> "Création"],
      ],
    ]
  ]);
  foreach ($rpicoms->contents() as $id => $rpicom) {
    foreach ($states as $state) {
      $vcom = interpolRpicom($rpicom, $state);
      $evt = array_shift($vcom);
      echo Yaml::dump(["$id@$state" => [$evt, $vcom]]);
    }
  }
  die("Arrêt ligne ".__LINE__);
}


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


echo "<a href='?action=testInterpolRpicom'> Test de la fonction interpolRpicom()</a><br>\n";

