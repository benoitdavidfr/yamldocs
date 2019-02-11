<?php
// conversion d'un document Yaml conforme au format Covadis
// ex http://localhost/covadis/?std=Plan-de-prevention-des-risques-PPRN-PPRT&action=showjson
// en un document conforme au schéma http://ydclasses.georef.eu/FDsSpecs
// Benoit DAVID - 11/2/2019

if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

require_once __DIR__.'/../../../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

$text = file_get_contents(__DIR__.'/ppr-v1.yaml');
$covadis = Yaml::parse($text, Yaml::PARSE_DATETIME);

$specs = [
  'title'=> "$covadis[nom], version $covadis[versionRegle]",
  'creator'=> [
    "Ministère de la Transition écologique (MTES)",
    "Ministère de la Cohésion des territoites et des Relations avec les collectivités territoriales (MCTRCT)",
    "Centre d’études et d’expertise sur les risques, l’environnement, la mobilité et l’aménagement (Cerema)",
  ],
  'issued'=> $covadis['dateRegle'],
  '$schema' => 'http://ydclasses.georef.eu/FDsSpecs',
  'codeLists'=> [],
  'featureCollections'=> [],
];

// traduction de la liste des typesEnumeres du géo-standard en codeLists
foreach ($covadis['typesEnumeres'] as $nom => $typesEnumere) {
  $codeList = [
    'title'=> $typesEnumere['nom'],
    'description'=> $typesEnumere['definition'],
    'codeLength'=> (int)$typesEnumere['longueurCode'],
    'items'=> [],
  ];
  foreach ($typesEnumere['items'] as $item) {
    $code = [ 'label'=> $item['libelle'] ];
    if (isset($item['definition']))
      $code['definition'] = $item['definition'];
    $codeList['items'][$item['code']] = $code;
  }
  $specs['codeLists'][$nom] = $codeList;
}
  
// affecte $val au sous-élément de $var défini par $path
if (!function_exists('set')) {
  function set(&$var, array $path, array $val) {
    //echo "set(var, path=",implode('/',$path),", val)<br>\n";
    $key = array_shift($path);
    if (!$path) {
      $var[$key] = $val;
    }
    else {
      set($var[$key], $path, $val);
    }
  }
}

// traduction des tables du géo-standard en featureCollections
$geometryConversion = [
  'N'=> 'None',
  'S'=> 'MultiPolygon',
  'L'=> 'MultiLineString',
  'P'=> 'MultiPoint',
];

foreach ($covadis['tables'] as $tname => $table) {
  if (!isset($geometryConversion[$table['geometrie']]))
    die("geometrie $table[geometrie] inconnue");
  $fColl = [
    'title'=> $table['nom'],
    'description'=> $table['definition'],
    'geometryType'=> $geometryConversion[$table['geometrie']],
    //'obligatoire'=> $table['obligatoire'],
    'mandatory'=> ($table['obligatoire']=='true'),
    'classification'=> $table['classement'],
    'regexName'=> $table['nomRegex'],
    'properties'=> [],
  ];
  foreach ($table['champs'] as $champ) {
    $property = [
      'description'=> $champ['definition'],
      'required'=> ($champ['valeurNulleInterdite']=='true'),
    ];
    switch ($champ['typeInformatique']) {
      case 'chaine':
        $property['type'] = 'string';
        $property['length'] = (int)$champ['longueur'];
        break;
      case 'entier': $property['type'] = 'integer'; break;
      case 'date': $property['type'] = 'date'; break;
      default:
        die("typeInformatique $champ[typeInformatique] inconnu");
    }
    if (isset($champ['typeEnumere'])) {
      if (!isset($covadis['typesEnumeres'][$champ['typeEnumere']]))
        echo "<b>Erreur le type énuméré $champ[typeEnumere] est utilisé dans $tname.$champ[nom] ",
             "sans être défini</b><br>\n";
      $property['enum'] = $champ['typeEnumere'];
    }
    $fColl['properties'][$champ['nom']] = $property;
  }
  set($specs['featureCollections'], explode('/',"$table[classement]/$table[nom]"), $fColl);
}

return $specs;