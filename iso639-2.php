<?php
/*PhpDoc:
title: génération iso639-2
*/
// Seul l'utilisateur benoit a le droit de modifier le code
if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

require_once __DIR__.'/../yd.inc.php';

// initialisation du résultat
$result = [];
$yaml = [
  'title'=> "Codification des langues selon la norme ISO 639-2",
  'language'=> ['fr','en'],
  'domainScheme'=>[
    'hasTopConcept'=> ['iso639-2']
  ],
  domains:
    iso639-2:
      prefLabel: ISO 639-2
  schemes:
    prefLabel:
      fr: Codification des langues selon la norme ISO 639-2
  concepts:
  
  'data'=> $result,
];
return new YamlSkos($yaml);
