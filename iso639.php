<?php
/*PhpDoc:
title: génération du Thésaurus ISO 639 selon les codifications alpha-2 et alpha-3
doc: |
  Le script exploite le fichier iso638.tsv qui est un copié/collé du tableau de la page
  https://www.loc.gov/standards/iso639-2/php/English_list.php
  dont la première ligne indique les libellé des colonnes.
journal: |
  25/7/2018:
    création
*/
use Symfony\Component\Yaml\Yaml;

// Seul l'utilisateur benoit a le droit de modifier le code
if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];
else {
  //require_once __DIR__.'/../yd.inc.php';


  // initialisation du résultat
  $text = <<<EOT
title: Codifications des langues selon la norme ISO 639
language: [fr, en]
source: https://www.loc.gov/standards/iso639-2/php/English_list.php
description: |
  Ce document présente la norme ISO 639 en 2 thésaurus, le premier pour les codes alpha-2 (ISO 639-1)
  et le second pour les codes alpha-3 (ISO 639-2).  
  Pour certaines langues, il existe 2 codes ISO 639-2 pour des raisons historiques,
  un code bibliographique (ISO 639-2/B) et un code terminologique (ISO 639-2/T).
  Dans ce cas l'indication (ISO 639-2/T) est ajoutée derrière le nom de la langue du code terminologique.  
  Ces thésaurus sont produits à partir du document cité en source.
domainScheme:
  prefLabel:
    fr: Codifications des langues selon la norme ISO 639
    en: Codes for the Representation of Names of Languages
  hasTopConcept:
    - iso639
domains:
  iso639:
    prefLabel:
      fr: Codifications
schemes:
  alpha2:
    prefLabel:
      fr: Codification selon la partie 1 de la norme ISO 639 (alpha 2)
      en: Alpha-2 codes for the Representation of Names of Languages (ISO 639-1)
    domain: [iso639]
  alpha3:
    prefLabel:
      fr: Codification selon la partie 2 de la norme ISO 639 (alpha 3)
      en: Alpha-3 codes for the Representation of Names of Languages (ISO 639-2)
    domain: [iso639]
concepts:

EOT;
  $yaml = Yaml::parse($text, Yaml::PARSE_DATETIME);

  function conceptIso639(array $data, string $scheme, $nature='') {
    $enlabels = explode('; ', trim($data[1]));
    $frlabels = explode('; ', trim($data[2]));
    $concept = [
      'prefLabel'=>[ 'fr'=> array_shift($frlabels).$nature, 'en'=> trim($data[0]).$nature ],
      'inScheme'=> [ $scheme ],
      'topConceptOf'=> [ $scheme ],
    ];
    if ($frlabels)
      $concept['altLabel']['fr'] = $frlabels;
    $enlabels = array_diff($enlabels, [trim($data[0])]);
    if ($enlabels)
      $concept['altLabel']['en'] = array_values($enlabels);
    return $concept;
  }

  // [0] => English Name of Language [1] => All English Names [2] => All French Names [3] => ISO 639-2 [4] => ISO 639-1
  $concepts = [];
  $norow = 0;
  if (($handle = fopen('pub/iso639.tsv', 'r')) !== FALSE) {
    while (($data = fgetcsv($handle, 1000, "\t")) !== FALSE) {
      //print_r($data); echo "<br>";
      if ($norow > 0) {
        if ($ca2 = trim($data[4]))
          $concepts[$ca2] = conceptIso639($data, 'alpha2');
        if ($ca3 = trim($data[3])) {
          if (preg_match('!^(...)/(...)$!', $ca3, $matches)) {
            $concepts[$matches[1]] = conceptIso639($data, 'alpha3');
            $concepts[$matches[2]] = conceptIso639($data, 'alpha3', ' (ISO 639-2/T)');
          }
          else
            $concepts[$ca3] = conceptIso639($data, 'alpha3');
        }
      }
      $norow++;
    }
    fclose($handle);
  }

  $yaml['concepts'] = $concepts;
  //echo '<pre>',Yaml::dump($yaml, 999, 2),"</pre>\n";
  return new YamlSkos($yaml);
}
