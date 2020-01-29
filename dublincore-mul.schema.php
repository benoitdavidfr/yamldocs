<?php
// Génération du schéma multi-lingue à partir du schéma mono-lingue - 8/2/2019
// Dans le schéma mono-lingue, le mot-clé #MultiLingual identifie les lignes à remplacer
// Les blancs de début de ligne sont conservés, la suite de la ligne jusqu'à '#MultiLingual ' est supprimée

if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

require_once __DIR__.'/../vendor/autoload.php';
use Symfony\Component\Yaml\Yaml;

//require_once __DIR__.'/../../inc.php';
  
//echo 'Exécution de ',__FILE__,"<br>\n";
//echo "DIR=",__DIR__,"<br>\n";

$outputText = '';
$pattern = '!^( *)([^#]*)#MultiLingual !';
$pattern = '!^( *).*#MultiLingual (.*)$!';
foreach (file(__DIR__.'/dublincore.schema.yaml') as $line) {
  if (preg_match($pattern, $line, $matches)) {
    //echo "<pre>matches="; print_r($matches); echo "</pre>\n";
    $outputText .= preg_replace($pattern, '${1}${2}', $line, 1);
  }
  else
    $outputText .= $line;
}
//echo "<pre>$outputText</pre>\n";
// le script renvoie un array Php
$doc = Yaml::parse($outputText, Yaml::PARSE_DATETIME);
$doc['$id'] = 'http://id.georef.eu/dublincore-mul.schema';
$doc['description'] = <<<'EOT'
Ce schéma correspond à une fiche de MD Dublin Core multi-lingue ;
il est dérivé du [document dublincore.schema](?doc=dublincore.schema).
EOT;
return $doc;