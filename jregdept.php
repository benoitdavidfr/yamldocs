<?php
/*PhpDoc:
title: jointure geohisto/regions X geohisto/departements suivie d'un nest
*/
// Seul l'utilisateur benoit a le droit de modifier le code
if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

require_once __DIR__.'/../inc.php';
//require_once __DIR__.'/../ydclasses.inc.php';

// initialisation du résultat
$result = [];

// ouverture des 2 documents regions et departements
if (!($regions = new_doc('geohisto/regions')))
  die("Erreur d'ouverture de regions");
if (!($depts = new_doc('geohisto/departements')))
  die("Erreur d'ouverture de departements");

// itération sur les régions
foreach ($regions->extract('/data') as $region) {
  if (isset($region['successors'])) // si successors est défini, la région est périmée
    continue;
  // itération sur les départements
  foreach ($depts->extract('/data') as $dept) {
    if (isset($dept['successors'])) // si successors est défini, le département est périmé
      continue;
    // test si la région est un des parents du département
    if (isset($dept['parents']) && in_array($region['id'], $dept['parents'])) {
      // si oui alors ajout dans les résultats
      $result[] = [
        'rcode'=> $region['insee_code'], 'rname'=> $region['name'],
        'insee_code'=> $dept['insee_code'], 'name'=> $dept['name']
      ];
    }
  }
}
// Renvoi du résultat en y ajoutant le titre du document
return [
  'title'=> "jointure geohisto/regions X geohisto/departements suivie d'un regroupement des départements par région",
  // restructuration pour regrouper les départements par région et renommer les champs de région
  'data'=> YamlDoc::nest($result, ['rcode'=>'insee_code','rname'=>'name'], 'depts'),
];
