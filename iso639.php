<?php
/*PhpDoc:
title: génération du Thésaurus ISO 639 avce les codifications alpha-2 et alpha-3
doc: |
  La génération est effectuée à partir de iso639.csv qui provient de https://fr.wikipedia.org/wiki/Liste_des_codes_ISO_639-1
*/
// Seul l'utilisateur benoit a le droit de modifier le code
if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

require_once __DIR__.'/../yd.inc.php';

use Symfony\Component\Yaml\Yaml;

// initialisation du résultat
$text = <<<EOT
title: Codifications des langues selon la norme ISO 639
language: [fr, en]
source: https://fr.wikipedia.org/wiki/Liste_des_codes_ISO_639-1
description: |
  Ce document restructure la norme ISO 639 en 2 thésaurus, le premier pour les codes alpha-2 (ISO 639-1)
  et le second pour les codes alpha-3 (ISO 639-2).  
  Pour certaines langues, il existe 2 codes ISO 639-2 pour des raisons historiques,
  un code bibliographique (ISO 639-2/B) et un code terminologique (ISO 639-2/T).
  Dans ce cas l'indication (ISO 639-2/B) ou (ISO 639-2/T) est ajoutée derrière le nom de la langue.  
  Ces thésaurus sont produits à partir de l'article Wikipédia cité en source.
domainScheme:
  prefLabel:
    fr: Codifications des langues selon la norme ISO 639
  hasTopConcept:
    - iso639
domains:
  iso639:
    prefLabel:
      fr: Codifications
schemes:
  iso639-1:
    prefLabel:
      fr: Codification selon la partie 1 de la norme ISO 639 (alpha 2)
    domain: [iso639]
  iso639-2:
    prefLabel:
      fr: Codification selon la partie 2 de la norme ISO 639-2 (alpha 3)
    domain: [iso639]
concepts:
  
EOT;
$yaml = Yaml::parse($text, Yaml::PARSE_DATETIME);
$iso639_1 = [];
$iso639_2 = [];

// Le test est nécessaire car sinon la fonction est définie 2 fois
if (1) {
  function conceptiso639(array $data, string $scheme, $nature='') {
    $concept = [
      'prefLabel'=>[ 'fr'=> trim($data[3]).$nature, 'en'=> trim($data[5]).$nature ],
      'inScheme'=> [ $scheme ],
      'topConceptOf'=> [ $scheme ],
    ];
    if (isset($data[6]) && $data[6])
      $concept['comment'] = trim($data[6]);
    return $concept;
  }
}

$norow = 0;
if (($handle = fopen('pub/iso639.csv', 'r')) !== FALSE) {
  while (($data = fgetcsv($handle, 1000, "\t")) !== FALSE) {
    //print_r($data); echo "<br>";
    if ($norow > 1) {
      $id1 = trim($data[0]);
      $iso639_1[$id1] = conceptiso639($data, 'iso639-1');
      $id2 = trim($data[1]);
      if ($id2) {
        if (preg_match('!^(...)/(...)$!', $id2, $matches)) {
          $iso639_2[$matches[1]] = conceptiso639($data, 'iso639-2', ' (ISO 639-2/B)');
          $iso639_2[$matches[2]] = conceptiso639($data, 'iso639-2', ' (ISO 639-2/T)');
        }
        else
          $iso639_2[$id2] = conceptiso639($data, 'iso639-2');
      }
    }
    $norow++;
  }
  fclose($handle);
}
$yaml['concepts'] = array_merge($iso639_1, $iso639_2);
//echo '<pre>',Yaml::dump($yaml, 999, 2),"</pre>\n";
return new YamlSkos($yaml);
