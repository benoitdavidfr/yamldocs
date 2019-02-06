<?php
// Génération du schéma multi-lingue à partir du schéma mono-lingue - 5/2/2019
// Dans le schéma mono-lingue, le mot-clé #MultiLingual identifie les lignes à remplacer
// Les blancs de début de ligne sont conservés, la suite de la ligne jusqu'à '#MultiLingual ' est supprimée

if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

require_once __DIR__.'/../../../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

//require_once __DIR__.'/../../inc.php';
  
//echo 'Exécution de ',__FILE__,"<br>\n";
//echo "DIR=",__DIR__,"<br>\n";

$outputText = '';
$pattern = '!^( *)([^#]*)#MultiLingual !';
foreach (file(__DIR__.'/schema2.yaml') as $line) {
  if (preg_match($pattern, $line, $matches)) {
    //echo "matches="; print_r($matches); echo "<br>\n";
    $outputText .= preg_replace($pattern, '${1}', $line, 1);
  }
  else
    $outputText .= $line;
}
// le script renvoie un array Php
return Yaml::parse($outputText, Yaml::PARSE_DATETIME);