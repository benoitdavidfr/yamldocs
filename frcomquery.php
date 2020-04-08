<?php
// frcomquery.php - interrogation du registre frcommunes

ini_set('memory_limit', '2048M');

require_once __DIR__.'/../vendor/autoload.php';

class AutoDescribed {
  protected $_id; // contient l'id
  protected $_c; // contient les champs
  function __get(string $key) { return $this->_c[$key] ?? null; }
};

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if (0) { // liste des préfectures
  if (!($communes = file_get_contents(__DIR__.'/frcommunes.pser')))
    die("Erreur d'ouverture de frcommunes.pser\n");
  $communes = unserialize($communes);

  if (!($departements = file_get_contents(__DIR__.'/frdepartements.pser')))
    die("Erreur d'ouverture de frdepartements.pser\n");
  $departements = unserialize($departements);

  foreach ($departements->contents as $cinsee => $deptv) {
    foreach($deptv as $foundingDate => $dept) {
      //echo "$cinsee@$foundingDate -> "; print_r($dept);
      if (!($chefLieu = $dept['chefLieu'] ?? null))
        echo "$cinsee@$foundingDate -> chefLieu on défini\n";
      elseif (isset($chefLieu['$ref'])) {
        //echo "$cinsee@$foundingDate -> ",$chefLieu['$ref'],"\n";
        $pos = strrpos($chefLieu['$ref'], '/');
        $cinseeChefLieu = substr($chefLieu['$ref'], $pos+1);
        //print_r($communes->contents[$cinseeChefLieu]);
        echo Yaml::dump(["$cinsee@$foundingDate" => $communes->contents[$cinseeChefLieu]], 99, 2);
      }
      else {
        //echo "$cinsee@$foundingDate -> "; print_r($chefLieu);
        foreach ($chefLieu as $refdate => $chefLieuD) {
          echo "$cinsee@$foundingDate -> $refdate -> ",$chefLieuD['$ref'],"\n";
          $pos = strrpos($chefLieuD['$ref'], '/');
          $cinseeChefLieu = substr($chefLieuD['$ref'], $pos+1);
          echo Yaml::dump(["$cinsee@$foundingDate" => [$refdate => $communes->contents[$cinseeChefLieu]]], 99, 2);
        }
      }
    }
  }
  die();
}

// Pour chaque commune, la première version, cad la plus ancienne, ne devrait pas avoir d'ancêtres
if (0) {
  if (!($communes = file_get_contents(__DIR__.'/frcommunes.pser')))
    die("Erreur d'ouverture de frcommunes.pser\n");
  $communes = unserialize($communes);
  $nberrors = 0;
  foreach ($communes->contents as $cinsee => $comv) {
    ksort($comv);
    $olderDate = array_keys($comv)[0];
    $com = $comv[$olderDate] ?? null;
    if (!$com) {
      echo "Erreur, pas de version la plus ancienne ($olderDate) : ";
      Yaml::dump([$cinsee => $comv], 99, 2);
    }
    elseif ($ancestors = ($com['ancestors'] ?? null)) {
      foreach ($ancestors as $i => $ancestor) {
        $ref = $ancestor['$ref'];
        if (!preg_match('!^http://id.georef.eu/frcommunes/([^@]*)@(.*)$!', $ref, $matches))
          die("No match on $ref\n");
        $ancestor = $communes->contents[$matches[1]][$matches[2]] ?? null;
        if (!$ancestor)
          die("No ancestor $ref\n");
        $com['ancestors'][$i] = $ancestor;
      }
      echo Yaml::dump(["$cinsee@$olderDate" => $com], 99, 2);
      $nberrors++;
    }
  }
  die("$nberrors erreurs détectées\n");
}

// Affiche les communes qui n'ont pas d'ancêtres et dont la date de création n'est pas le 1/1/1942
if (1) {
  if (!($communes = file_get_contents(__DIR__.'/frcommunes.pser')))
    die("Erreur d'ouverture de frcommunes.pser\n");
  $communes = unserialize($communes);
  $nberrors = 0;
  foreach ($communes->contents as $cinsee => $comv) {
    foreach ($comv as $foundingDate => $com) {
      if (!isset($com['ancestors']) && ($com['foundingDate'] <> '1942-01-01')) {
        echo Yaml::dump(["$cinsee@$foundingDate" => $com], 99, 2);
        $nberrors++;
      }
    }
  }
  die("$nberrors erreurs détectées\n");
}

die("Aucune action définie\n");