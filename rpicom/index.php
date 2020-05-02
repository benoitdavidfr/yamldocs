<?php
/*PhpDoc:
name: index.php
title: index.php - diverses actions rpicom
doc: |
  Définition de différentes actions accessibles par le Menu
journal: |
  2/5/2020:
    - abandon de setGeoloc
    - 1ère version aboutie de geoloc, 46953 objets traités dont 11 erreurs et 7001 à voir
  27-19/4/2020:
    - suite setGéoloc
    - correction de 2 erreurs probables dans com20200101 et rpicom
  25-26/4/2020:
    - étude AE et GéoFLA pour voir quelles versions peuvent être géolocalisées
    - écriture des setGéoloc
    - soulèvent plusieurs problèmes
      - GéoFla n'a que les communes simples, pas les rattachées
      - AeCog n'a pas la géométrie du territoire sans associés, il faudrait faire la différence avec les associés
  24/4/2020:
    - modification du schéma de rpicom
    - réécriture de addToRpicom() dans le cas 32 pour traiter le changement de c. nouvelle de rattachement
    - l'interpolation d'un état à une date à partir de rpicom fonctionne et met en lumière des bugs INSEE
  23/4/2020:
    - gestion de la concomitance de plusieurs GroupMvts sur une même entité
      - définition de la classe MultiGroupMvts qui gère cette concomitance
      - enregistrement dans rpicom.yam de dates bis non conformes au schéma
    - j'ai détecté 3 autres dates bis non dues à cette concomitance -> bug dans addToRpicom() dans le cas 32
  22/4/2018:
    - bug identifié dans brpicom sur Pont-Farcy qui subit 2 opérations le 1/1/2018:
      1) en tant que 14513 absorbe 14507 (mod=34) et change de département pour prendre l'id 50649
      2) en tant que 50649 devient déléguée de Tessy-Bocage (50592)
    - bug identifié dans brpicom, plusieurs opérations peuvent intervenir à la même date sur un id donné
    - cela remet en cause le schéma de rpicom
  21/4/2020:
    - modif schéma exfcoms.yaml et génération correspondante dans buidState
    - modif schema exrpicom.yaml et génération correspondante
    - comtage du nbre d'évolutions sur les communes simples
  18/4/2020:
    - possibilité d'utiliser le script en cli permettant ainsi de générer les fichiers avec Makefile
  11/4/2020:
    - extraction des classes Base et Criteria dans base.inc.php
    - correction de la définition de l'encodage des anciens ficheirs INSEE en Windows-1252
    - extraction de la classe GroupMvts dans grpmvts.inc.php
  10/4/2020:
    - genEvols semble fonctionner jusqu'au 1/1/2000
    - Pas réussi à comparer avec le fichier INSEE de cette date
    - Pb:
      - article intégré ou non dans le nom
      - des erreurs dans genEvols, Voir 04112
includes:
  - base.inc.php
  - grpmvts.inc.php
  - mgrpmvts.inc.php
  - geojfile.inc.php
  - rpicom.inc.php
  - ../inc.php
screens:
classes:
functions:
*/
/*
changeDeDépartementEtPrendLeCode -> quitteLeDépartementEtPrendLeCode
changeDeDépartementEtAvaitPourCode -> arriveDansLeDépartementAvecLeCode
*/
ini_set('memory_limit', '2048M');

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/menu.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

$menu = new Menu([
  // [{action} => [ 'argNames' => [{argName}], 'actions'=> [{label}=> [{argValue}]] ]]
  'csvMvt2yaml'=> [
    // affichage Yaml des mouvements
    'argNames'=> ['file'], // liste des noms des arguments en plus de action
    'actions'=> [
      "Affiche mvtcommune2020.csv"=> ['mvtcommune2020.csv'],
    ],
  ],
  'showGrpMvts'=> [
    'argNames'=> [], // liste des noms des arguments en plus de action
    'actions'=> [
      "Affiche les groupes de mouvements avec possibilité de sélection"=> [],
    ],
  ],
  'csvCom2Tab'=> [
    // affichage d'un instantané des communes en tab
    'argNames'=> ['file', 'format'], // liste des noms des arguments en plus de action
    'actions'=> [
      "Affiche communes2020.csv en tab"=> ['communes2020.csv', 'csv'],
      "Affiche lcommunes-01012019.csv en tab"=> ['communes-01012019.csv', 'csv'],
      "Affiche France2018.txt en tab"=> ['France2018.txt', 'txt'],
      "Affiche France2000.txt en tab"=> ['France2000.txt', 'txt'],
    ],
  ],
  'csvCom2html'=> [
    // affichage d'un instantané des communes
    'argNames'=> ['file', 'format'], // liste des noms des arguments en plus de action
    'actions'=> [
      "Affiche communes2020.csv"=> ['communes2020.csv', 'csv'],
      "Affiche les communes au 1/1/2019"=> ['communes-01012019.csv', 'csv'],
      "Affiche France2018.txt"=> ['France2018.txt', 'txt'],
      "Affiche France2000.txt"=> ['France2000.txt', 'txt'],
    ],
  ],
  'delBase'=> [
    'argNames'=> [],
    'actions'=> [
      "effacement de la base"=> [],
    ],
  ],
  'integrateState'=> [
    // intégration d'un état dans la base
    'argNames'=> ['state', 'file', 'format'], // liste des noms des arguments en plus de action
     // actions prédéfinies
    'actions'=> [
      "intégration dans la base de l'état au 1/1/2020"=> [ '2020-01-01', 'communes2020.csv', 'csv'],
      "intégration dans la base de l'état au 1/1/2010"=> [ '2010-01-01', 'France2010.txt', 'txt'],
      "intégration dans la base de l'état au 1/1/2000"=> [ '2000-01-01', 'France2000.txt', 'txt'],
    ],
  ],
  'integrateUpdates'=> [
    // intègre les Mvts dans la base et affiche le résultat, sans sauver la base
    'argNames'=> ['file'],
    'actions'=> [
      "affichage de la base avec les maj"=> [ 'mvtcommune2020.csv' ],
    ],
  ],
  'genCom1943'=> [
    // génération d'un fichier des communes au 1/1/1943 à partir de GéoHisto
    'argNames'=> [],
    'actions'=> [
      "génération du fichier des communes au 1/1/1943"=> [],
    ],
  ],
  'check'=> [
    // vérifie la conformité du fichier à son schéma
    'argNames'=> ['file'],
     // actions prédéfinies
    'actions'=> [
      "conformité com20200101.yaml à son schema"=> [ 'com20200101.yaml'],
    ],
  ],
  'buildState'=> [
    // affichage Yaml de l'état des communes par traduction du fichier INSEE
    'argNames'=> ['state', 'file', 'format'], // liste des noms des arguments en plus de action
     // actions prédéfinies
    'actions'=> [
      "affichage de l'état au 1/1/2020"=> [ '2020-01-01', 'communes2020.csv', 'csv'],
      "affichage de l'état au 1/1/2019"=> [ '2019-01-01', 'communes-01012019.csv', 'csv'],
      "affichage de l'état au 1/1/2018"=> [ '2018-01-01', 'France2018.txt', 'txt'],
      "affichage de l'état au 1/1/2017"=> [ '2017-01-01', 'France2017.txt', 'txt'],
      "affichage de l'état au 1/1/2010"=> [ '2010-01-01', 'France2010.txt', 'txt'],
      "affichage de l'état au 1/1/2000"=> [ '2000-01-01', 'France2000.txt', 'txt']
    ],
  ],
  'mvtsPatterns'=> [
    'argNames'=> [],
    'actions'=> [
      "génération des motifs des gpes de mouvements existants dans le fichier des mouvements"=> [],
    ],
  ],
  'analyzeMod32'=> [
    'argNames'=> [],
    'actions'=> [
      "analyse des mouvements de création de commune nouvelle (32)"=> [],
    ],
  ],
  'genEvols'=> [
    'argNames'=> [],
    'actions'=> [
      "génération du fichier des évolutions"=> [],
    ],
  ],
  'comp'=> [
    'argNames'=> ['date'],
    'actions'=> [
      "comparaison entre l'état généré au 1/1/2000 et celui de l'INSEE"=> ['2000-01-01'],
    ],
  ],
  'compare'=> [
    'argNames'=> [],
    'actions'=> [
      "Compare 2 fichiers entre eux"=> [],
    ],
  ],
  'test_hypothèse_brpicom'=> [
    'argNames'=> [],
    'actions'=> [
      "Test d'hypothèses / fabrication du Rpicom"=> [],
    ],
  ],
  'brpicom'=> [
    'argNames'=> [],
    'actions'=> [
      "Fabrication du Rpicom"=> [],
    ],
  ],
  'bbtest'=> [
    'argNames'=> [],
    'actions'=> [
      "Fabrication de la base de test du Rpicom"=> [],
    ],
  ],
  'nombres'=> [
    'argNames'=> [],
    'actions'=> [
      "Dénombrement des communes simples et de leurs évolutions"=> [],
    ],
  ],
  'interpolRpicom'=> [
    'argNames'=> ['state'],
    'actions'=> [
      "interpolation dans le rpicom de l'état au 1/1/2010"=> [ '2010-01-01'],
      "interpolation dans le rpicom de l'état au 1/1/2000"=> [ '2000-01-01'],
      "interpolation dans le rpicom de l'état au 1/1/1943"=> [ '1943-01-01'],
    ],
  ],
  'lectureAE'=> [
    'argNames'=> ['aepath'],
    'actions'=> [
      "lecture AE au 1/1/2020"=> [ 'adminexpress/AE-2020COG-WGS84G/ADE-COG_2-1_SHP_WGS84G_FRA'],
    ],
  ],
  'lectureGeoFLA'=> [
    'argNames'=> ['file'],
    'actions'=> [
      "lecture GeoFLA au 1/1/2011"=> [ 
        'data/GEOFLA_1-1_SHP_LAMB93_FR-ED111/GEOFLA/1_DONNEES_LIVRAISON_2013-12-00225/'
          .'GEOFLA_1-1_SHP_LAMB93_FR-ED111/COMMUNES/COMMUNE.geojson'
      ],
    ],
  ],
  'setGeolocGéoFla'=> [
    'argNames'=> ['file'],
    'actions'=> [
      "setGeolocGéoFla sur GeoFLA au 1/1/2011"=> [ 
        'data/GEOFLA_1-1_SHP_LAMB93_FR-ED111/GEOFLA/1_DONNEES_LIVRAISON_2013-12-00225/'
          .'GEOFLA_1-1_SHP_LAMB93_FR-ED111/COMMUNES/COMMUNE.geojson'
      ],
    ],
  ],
  'transcode'=> [
    'argNames'=> [],
    'actions'=> [
      "Génération de la table de transcodage"=> [],
    ],
  ],
], $argc ?? 0, $argv ?? []);

if (($_GET['action'] ?? null) == 'delBase') { // suppression de la base
  if (is_file(__DIR__.'/base.pser')) {
    unlink(__DIR__.'/base.pser');
    echo "Le fichier base.pser a été effacé<br>\n";
  }
  unset($_GET['action']);
}

{/*PhpDoc: screens
name: Menu
title: Menu - permet d'exécuter différentes actions définies
*/}
if (!isset($_GET['action'])) { // Menu
  $menu->show();
  die();
}

// PERIME - Classe des mouvements, agrége plusieurs lignes en un mouvement
class Mvt {
  const ModVals = [ // modalités de mod
    '10'=> "Changement de nom",
    '20'=> "Création",
    '21'=> "Rétablissement",
    '30'=> "Suppression",
    '31'=> "Fusion simple",
    '32'=> "Création de commune nouvelle",
    '33'=> "Fusion association",
    '34'=> "Transformation de fusion association en fusion simple ou suppression de communes déléguées",
    '41'=> "Changement de code dû à un changement de département",
    '50'=> "Changement de code dû à un transfert de chef-lieu",
    '70'=> "Transformation de commune associé en commune déléguée",
  ];
  protected $mod;
  protected $date_eff;
  protected $avs; // liste des av
  protected $aps; // liste des ap
  
  // nouveau Mvt
  function __construct(array $rec) {
    $this->mod = $rec['mod'];
    $this->date_eff = $rec['date_eff'];
    $this->avs = [[
      'type'=> $rec['typecom_av'],
      'com'=> $rec['com_av'],
      'libelle'=> $rec['libelle_av'],
    ]];
    $this->aps = [[
      'type'=> $rec['typecom_ap'],
      'com'=> $rec['com_ap'],
      'libelle'=> $rec['libelle_ap'],
    ]];
  }
  
  // agrège une nouvelle ligne si elle appartient au même mouvement et retourne alors true, sinon retourne false
  function agg(array $rec): bool {
    if (($rec['mod']<>$this->mod) || ($rec['date_eff']<>$this->date_eff))
      return false;
    if (in_array($this->mod, ['34'])) { // même com_ap
      if ($rec['com_ap'] <> $this->aps[0]['com'])
        return false;
      else {
        $this->avs[] = [
          'type'=> $rec['typecom_av'],
          'com'=> $rec['com_av'],
          'libelle'=> $rec['libelle_av'],
        ];
        return true;
      }
    }
    elseif (in_array($this->mod, ['70'])) { // aucune agrégation
      return false;
    }
    return false;
  }
  
  function asArray(): array {
    return [
      'mod'=> $this->mod,
      'modVal'=> self::ModVals[$this->mod],
      'date_eff'=> $this->date_eff,
      'avs'=> $this->avs,
      'aps'=> $this->aps,
    ];
  }
};

if ($_GET['action'] == 'csvMvt2yaml') { // affichage Yaml des mouvements
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>mvts</title></head><body><pre>\n";
  $headersToDelete = ['tncc_av','ncc_av','nccenr_av','tncc_ap', 'ncc_ap', 'nccenr_ap'];
  $mvtsUniq = []; // Utilisé pour la vérification d'unicité des enregistrements
  echo "title: lecture du fichier $_GET[file]\n";
  $file = fopen($_GET['file'], 'r');
  $headers = fgetcsv($file);
  $mvt = null;
  $nbrec = 0;
  echo "<table border=1>\n";
  while($record = fgetcsv($file)) {
    //print_r($record);
    $json = json_encode($record, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    //echo "record($nbrec)=$json\n";
    if (isset($mvtsUniq[$json])) {
      //echo "<tr><td colspan=8><b>Erreur de doublon sur $json</b></td></tr>\n";
      continue;
    }
    $mvtsUniq[$json] = 1;
    $rec = [];
    foreach ($headers as $i => $header) {
      if (!in_array($header, $headersToDelete))
        $rec[$header] = $record[$i];
    }
    //print_r($rec);
    //echo str_replace("-\n ", '-', Yaml::dump([0 => $rec], 99, 2));
    if ($nbrec == 0)
      echo "<th>",implode('</th><th>', array_keys($rec)),"</th>\n";
    echo "<tr><td>",implode('</td><td>', $rec),"</td><td>",Mvt::ModVals[$rec['mod']],"</td></tr>\n";
    $yaml = [
      'mod'=> $rec['mod'],
      'modVal'=> Mvt::ModVals[$rec['mod']],
      'date_eff'=> $rec['date_eff'],
      'avant'=> [
        'typecom'=> $rec['typecom_av'],
        'com'=> $rec['com_av'],
        'libelle'=> $rec['libelle_av'],
      ],
      'après'=> [
        'typecom'=> $rec['typecom_ap'],
        'com'=> $rec['com_ap'],
        'libelle'=> $rec['libelle_ap'],
      ],
    ];
    $nbrec++;
    //echo str_replace("-\n ", '-', Yaml::dump([0 => $yaml], 2, 2));
    /*if (!$mvt) {
      $mvt = new Mvt($rec);
      echo Yaml::dump([0 => $rec]);
    }
    elseif ($mvt->agg($rec)) {
      echo Yaml::dump([0 => $rec]);
      echo "Agg ok\n";
    }
    else {
      echo "Agg KO\n";
      //print_r($mvt);
      echo Yaml::dump([$mvt->asArray()], 3, 2);
      $mvt = new Mvt($rec);
      echo Yaml::dump([0 => $rec]);
    }*/
    //if (++$nbrec >= 100) die("nbrec >= 100");
  }
  die();
}

if ($_GET['action'] == 'csvCom2Tab') { // affichage tabulaire d'un fichier des communes
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>etat $_GET[file]</title></head><body>\n";
  echo "<h3>Fichier $_GET[file]</h3>\n";
  $file = fopen($_GET['file'], 'r');
  $sep = $_GET['format'] == 'csv' ? ',' : "\t";
  $headers = fgetcsv($file, 0, $sep);
  // un des fichiers comporte des caractères parasites au début ce qui perturbe la détection des headers
  foreach ($headers as $i => $header)
    if (preg_match('!"([^"]+)"!', $header, $matches))
      $headers[$i] = $matches[1];
  echo "<table border=1>\n",
    '<th>',implode('</th><th>', $headers),"</th>\n";
  while($record = fgetcsv($file, 0, $sep)) {
    echo "<tr><td>",implode('</td><td>', $record),"</td></tr>\n";
  }
  echo "</table>\n";
  die();
}

if ($_GET['action'] == 'csvCom2html') { // affichage d'un instantané des communes
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>etat $_GET[file]</title></head><body>\n";
  $headersToDelete = [];
  $coms = []; // [cinsee => record + children] 
  $enfants = []; // [cinsee => record] 
  //echo "<pre>";
  echo "<h3>lecture du fichier $_GET[file]</h3>\n";
  $file = fopen($_GET['file'], 'r');
  $sep = $_GET['format'] == 'csv' ? ',' : "\t";
  $headers = fgetcsv($file, 0, $sep);
  // un des fichiers comporte des caractères parasites au début ce qui perturbe la détection des headers
  foreach ($headers as $i => $header)
    if (preg_match('!"([^"]+)"!', $header, $matches))
      $headers[$i] = $matches[1];
  //echo "<pre>headers="; print_r($headers); echo "</pre>\n";
  $nbrec = 0;
  $nbcomsimple = 0; // nbre de communes simples (COM), soit nouvelle, soit principale issue d'une fusion-assoc
  $nbcomd = 0; // nbre de cpommunes déléguées
  $nbcoma = 0; // nbre de communes associées
  $nbcomArdtM = 0; // nbre d'arrondissements municipaux
  $comComposites = []; // communes composites, cad composées de communes déléguées
  while($record = fgetcsv($file, 0, $sep)) {
    $rec = [];
    foreach ($headers as $i => $header) {
      if (!in_array($header, $headersToDelete))
        $rec[strtolower($header)] = $_GET['format'] == 'csv' ? $record[$i] : utf8_encode($record[$i]);
    }
    //echo "<pre>rec="; print_r($rec); echo "</pre>\n";
    if ($_GET['format'] == 'txt') {
      switch($rec['actual']) {
        case '1': // commune simple
          $typecom = 'COM'; break;
        case '2': // commune « associée »
          $typecom = 'COMA'; break;
        case '5': // arrondissement municipal
          $typecom = 'ARM'; break;
        case '6': // Commune déléguée
          $typecom = 'COMD'; break;
        default:
          $typecom = 'X'; break;
      }
      if ($typecom == 'X')
        continue;
      $rec['typecom'] = $typecom;
      $rec['com'] = "$rec[dep]$rec[com]";
      $rec['comparent'] = $rec['pole'];
    }
    else {
      $typecom = $rec['typecom'];
    }
    //echo "$rec[nccenr] ($typecom $rec[com])<br>\n";
    if (!$rec['comparent']) {
      $coms[$rec['com']] = $rec;
      $nbcomsimple++;
    }
    else {
      $enfants[$rec['com']] = $rec;
      if ($rec['typecom']=='COMD')
        $nbcomd++;
      elseif ($rec['typecom']=='COMA')
        $nbcoma++;
      else // ARM
        $nbcomArdtM++;
    }
    $nbrec++;
    //if ($nbrec >= 10) die("<b>die nbrec >= 10</b>");
  }
  ksort($coms);
  foreach ($enfants as $c => $enfant) {
    $comparent = $enfant['comparent'];
    $coms[$comparent]['enfants'][$c] = $enfant;
    if (in_array($enfant['typecom'], ['COMD', 'ARM']))
      $comComposites[$comparent] = 1;
  }
  echo "<b>$nbrec enr. lus, $nbcomsimple c. simples, $nbcoma c. associées, ",
    count($comComposites)," c. composites, $nbcomd c. déléguées, $nbcomArdtM ardt municipaux</b>\n";
  //echo "<pre>coms="; print_r($coms); echo "</pre>\n";
  echo "<pre>\n";
  foreach ($coms as $c => $com) {
    $typecom = $rec['typecom'] ?? null;
    $href = "?action=extrait&amp;insee=$com[com]&amp;file=$_GET[file]&amp;format=$_GET[format]";
    $yaml = [
      'name'=> "$com[nccenr] ($typecom <a href=\"$href\">$com[com]</a>)",
    ];
    if (isset($com['enfants'])) {
      $yaml['enfants'] = [];
      foreach ($com['enfants'] as $enfant)
        $yaml['enfants'][] = "$enfant[nccenr] ($enfant[typecom] $enfant[com])";
    }
      
    echo str_replace("-\n  ", "- ", Yaml::dump([$yaml], 3, 2));
  }
  die();
}

// convertit un enregistrement txt en csv, cad de l'ancien format INSEE dans le nouveau
function conv2Csv(array $rec): array {
  switch($rec['actual']) {
    case '1': // commune simple
      $rec['typecom'] = 'COM'; break;
    case '2': // commune « associée »
      $rec['typecom'] = 'COMA'; break;
    case '5': // arrondissement municipal
      $rec['typecom'] = 'ARM'; break;
    case '6': // Commune déléguée
      $rec['typecom'] = 'COMD'; break;
    default:
      $rec['typecom'] = 'X'; break;
  }
  $rec['com'] = "$rec[dep]$rec[com]";
  $artmin = '';
  if ($rec['artmin']) {
    $artmin = substr($rec['artmin'], 1, strlen($rec['artmin'])-2); // supp ()
    if (!in_array($artmin, ["L'"]))
      $artmin .= ' ';
  }
  $rec['libelle'] = $artmin.$rec['nccenr'];
  
  $rec['comparent'] = $rec['pole'];
  return $rec;
}

if ($_GET['action'] == 'integrateState') { // intégration d'un état dans la base
  if (is_file(__DIR__.'/base.pser')) {
    $base = unserialize(file_get_contents(__DIR__.'/base.pser'));
  }
  else {
    $base = [];
  }
  echo "<h3>lecture du fichier $_GET[file]</h3>\n";
  $coms = []; // [cinsee => record + children] 
  $enfants = []; // [cinsee => record] 
  $file = fopen($_GET['file'], 'r');
  $sep = $_GET['format'] == 'csv' ? ',' : "\t";
  $headers = fgetcsv($file, 0, $sep);
  // un des fichiers comporte des caractères parasites au début ce qui perturbe la détection des headers
  foreach ($headers as $i => $header)
    if (preg_match('!"([^"]+)"!', $header, $matches))
      $headers[$i] = $matches[1];
  //echo "<pre>headers="; print_r($headers); echo "</pre>\n";
  while($record = fgetcsv($file, 0, $sep)) {
    $rec = [];
    foreach ($headers as $i => $header) {
      $rec[strtolower($header)] = $_GET['format'] == 'csv' ? $record[$i] : utf8_encode($record[$i]);
    }
    //echo "<pre>rec="; print_r($rec); echo "</pre>\n";
    if ($_GET['format'] == 'txt') {
      $rec = conv2Csv($rec);
      if ($rec['typecom'] == 'X')
        continue;
    }
    //echo "$rec[nccenr] ($typecom $rec[com])<br>\n";
    if (!$rec['comparent']) {
      $base[$rec['com']]['states'][$_GET['state']] = ['name'=> $rec['nccenr']];
    }
    else {
      $enfants[$rec['com']] = $rec;
    }
    //if ($nbrec >= 10) die("<b>die nbrec >= 10</b>");
  }
  foreach ($enfants as $c => $enfant) {
    $comparent = $enfant['comparent'];
    if ($enfant['typecom'] == 'COMA')
      $childrenTag = 'associées'; 
    elseif ($enfant['typecom'] == 'COMD')
      $childrenTag = 'déléguées'; 
    elseif ($enfant['typecom'] == 'ARM')
      $childrenTag = 'ardts'; 
    $base[$comparent]['states'][$_GET['state']][$childrenTag][$c] = ['name'=> $enfant['nccenr']];
  }
  file_put_contents(__DIR__.'/base.pser', serialize($base));
  echo "<pre>\n";
  echo str_replace("-\n  ", "- ", Yaml::dump($base, 99, 2));
  die();
}

{/*PhpDoc: functions
name: addValToArray
title: "function addValToArray($val, &$array): void - ajoute $val à $array, si $array existe alors $val est ajouté, sinon $array est créé à [ $val ]"
doc: |
  $array n'existe pas ou contient un array
  Le paramètre $array n'existe pas forcément. Par exemple si $a = [] on peut utiliser $a['key'] comme paramètre.
*/}
function addValToArray($val, &$array): void {
  if (!isset($array))
    $array = [ $val ];
  else
    $array[] = $val;
}

{/*PhpDoc: functions
name: addScalarToArrayOrScalar
title: "function addScalarToArrayOrScalar($scalar, &$arrayOrScalar): void - ajoute $scalar à $arrayOrScalar"
doc: |
  Dans cette version, $scalar est un scalaire et $arrayOrScalar n'existe pas ou contient un scalaire ou un array
  si $arrayOrScalar n'existe pas alors il prend la valeur $scalar
  Sinon si $arrayOrScalar est un scalaire alors il devient un array contenant l'ancienne valeur et la nouvelle
  sinon si $arrayOrScalar est un array alors $scalar lui est ajouté
  sinon exception
  Le paramètre $arrayOrScalar n'existe pas forcément. Par exemple si $a = [] on peut utiliser $a['key'] comme paramètre.
*/}
function addScalarToArrayOrScalar($scalar, &$arrayOrScalar): void {
  if (!is_scalar($scalar))
    throw new Exception("Erreur dans addScalarToArrayOrScalar(), le 1er paramètre doit être un scalaire");
  if (!isset($arrayOrScalar))
    $arrayOrScalar = $scalar;
  elseif (is_scalar($arrayOrScalar))
    $arrayOrScalar = [$arrayOrScalar, $scalar];
  elseif (is_array($arrayOrScalar))
    $arrayOrScalar[] = $scalar;
  else
    throw new Exception("Erreur dans addScalarToArrayOrScalar(), le 2e paramètre doit être indéfini, un scalaire ou un array");
}
if (0) { // Test addScalarToArrayOrScalar()
  echo "<pre>\n";
  addScalarToArrayOrScalar('val1', $array['key']); echo Yaml::dump(['addScalarToArrayOrScalar'=> $array]),"<br>\n";
  addScalarToArrayOrScalar('val2', $array['key']); echo Yaml::dump(['addScalarToArrayOrScalar'=> $array]),"<br>\n";
  die("Fin test addValToArrayOrScalar");
}

if ($_GET['action'] == 'integrateUpdates') { // intègre les Mvts dans la base et affiche le résultat, sans sauver la base
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>mvts</title></head><body>\n";
  $base = is_file(__DIR__.'/base.pser') ? unserialize(file_get_contents(__DIR__.'/base.pser')) : [];
  $file = fopen($_GET['file'], 'r');
  $headers = fgetcsv($file);
  echo "<pre>\n";
  while($record = fgetcsv($file)) {
    //print_r($record);
    $rec = [];
    foreach ($headers as $i => $header) {
      $rec[$header] = $record[$i];
    }
    //print_r($rec);
    $modif = [
      'mod'=> $rec['mod'],
      'label'=> Mvt::ModVals[$rec['mod']],
      'input'=> [
        'type'=> $rec['typecom_av'],
        'com'=> $rec['com_av'],
        'name'=> $rec['nccenr_av'],
      ],
      'output'=> [
        'type'=> $rec['typecom_ap'],
        'com'=> $rec['com_ap'],
        'name'=> $rec['nccenr_ap'],
      ],
    ];
    if ($rec['com_av'] == $rec['com_ap']) {
      addValToArray($modif, $base[$rec['com_av']]['updates'][$rec['date_eff']]['srceAndDest']);
    }
    else {
      addValToArray($modif, $base[$rec['com_av']]['updates'][$rec['date_eff']]['source']);
      addValToArray($modif, $base[$rec['com_ap']]['updates'][$rec['date_eff']]['destination']);
    }
  }
  // Suppression des communes ne comportant aucune modification
  foreach ($base as $cinsee => $com) {
    if (!isset($com['updates']))
      unset($base[$cinsee]);
  }
  echo preg_replace('!-\n +!', '- ', Yaml::dump($base, 6, 2));
  die();
}

if ($_GET['action'] == 'genCom1943') { // génération d'un fichier des communes au 1/1/1943 à partir de GéoHisto
  $ardtsM = [];
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>genCom1943</title></head><body><pre>\n";
  $fcom = fopen(__DIR__.'/../../geohisto/communess.csv','r');
  $headers = fgetcsv($fcom);
  //echo "headers="; print_r($headers);
  while ($record = fgetcsv($fcom)) {
    //echo "record="; print_r($record);
    $rec = [];
    foreach ($headers as $i => $header)
      $rec[$header] = $record[$i];
    if ($rec['start_datetime'] <> '1942-01-01 00:00:00')
      continue;
    if (in_array(substr($rec['insee_code'], 0, 2), ['2A','2B'])) // Erreurs manifestes
      continue;
    //echo "rec="; print_r($rec);
    // Gestion des arrdts communaux
    if (preg_match('!Arrondissement!', $rec['name']))
      $ardtsM[$rec['insee_code']] = [
        'name'=> $rec['name'],
        //'rec'=> $rec,
      ];
    else
      $yaml[$rec['insee_code']] = [
        'name'=> $rec['name'],
        //'rec'=> $rec,
      ];
  }
  // Rattachement des arrdts communaux à leur commune de rattachement
  foreach ($ardtsM as $cinsee => $ardtM) {
    if (substr($cinsee, 0, 2)=='13') {
      $yaml['13055']['aPourArrondissementsMunicipaux'][$cinsee] = $ardtM;
      $yaml[$cinsee]['estArrondissementMunicipalDe'] = '13055';
    }
    elseif (substr($cinsee, 0, 2)=='69') {
      $yaml['69123']['aPourArrondissementsMunicipaux'][$cinsee] = $ardtM;
      $yaml[$cinsee]['estArrondissementMunicipalDe'] = '69123';
    }
    elseif (substr($cinsee, 0, 2)=='75') {
      $yaml['75056']['aPourArrondissementsMunicipaux'][$cinsee] = $ardtM;
      $yaml[$cinsee]['estArrondissementMunicipalDe'] = '75056';
    }
  }
  // Suppression des communes de Mayotte qui n'étaient pas en 1943 dans périmètre du Rpicom
  foreach ($yaml as $id => $com) {
    if (substr($id, 0, 3) == '976')
      unset($yaml[$id]);
  }
  // Ajout des communes de Saint-Martin et de Saint-Barthlémy qui étaient dans le périmètre en 1943
  $yaml['97123'] = ['name'=> "Saint-Barthélemy"];
  $yaml['97127'] = ['name'=> "Saint-Martin"];
  ksort($yaml);
  $buildNameAdministrativeArea = <<<'EOT'
    if (isset($item['name']))
      return "$item[name] ($skey)";
    elseif (isset($item['estAssociéeA']))
      return "$skey estAssociéeA $item[estAssociéeA]";
    elseif (isset($item['estDéléguéeDe']))
      return "$skey estDéléguéeDe $item[estDéléguéeDe]";
    elseif (isset($item['estArrondissementMunicipalDe']))
      return "$skey estArrondissementMunicipalDe $item[estArrondissementMunicipalDe]";
    else
      return "none";
EOT;
  echo Yaml::dump([
      'title'=> "Fichier des communes de 1943",
      'created'=> date(DATE_ATOM),
      'source'=> "Fabriqué à partir de GéoHisto en utilisant http://localhost/yamldoc/pub/evolcoms/?action=genCom1943",
      '$schema'=> 'http://id.georef.eu/rpicom/exfcoms/$schema',
      'ydADscrBhv'=> [
        'jsonLdContext'=> 'http://schema.org',
        'firstLevelType'=> 'AdministrativeArea',
        'buildName'=> [ # définition de l'affichage réduit par type d'objet, code Php par type
          'AdministrativeArea'=> $buildNameAdministrativeArea,
        ],
        'writePserReally'=> true,
      ],
      'contents'=> $yaml,
    ], 99, 2);
  die();
}

if ($_GET['action'] == 'check') { // vérifie la conformité du fichier à son schéma
  require_once __DIR__.'/../../inc.php';
  $docid = 'rpicom/'.substr($_GET['file'], 0, strrpos($_GET['file'], '.'));
  //echo "docid=$docid\n";
  $doc = new_doc($docid, 'pub');
  $doc->checkSchemaConformity('/');
  die();
}

// Attention base.inc.php et YamDoc sont incompatibles
require_once __DIR__.'/base.inc.php';
require_once __DIR__.'/grpmvts.inc.php';
require_once __DIR__.'/mgrpmvts.inc.php';

{/*PhpDoc: screens
name: mvtsPatterns
title: mvtsPatterns - génération des motifs de mouvements existants dans le fichier des mouvements INSEE
doc: |
  Le résultat est l'affichage d'un tableau des motifs des mouvements distincts.
  Si le paramètre example est défini alors pour caque motif un exemple est fourni pour faciliter la compréhension.
*/}
if ($_GET['action'] == 'mvtsPatterns') { // génération des motifs de mouvements existants dans le fichier des mouvements
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>mvtsPatterns</title></head><body><pre>\n";
  $patterns = []; // liste des patterns produits avec comme clé le JSON du pattern sans example
  //$trace = new Criteria([]); // full trace
  $trace = new Criteria(['not']); // NO trace
  $mvtcoms = GroupMvts::readMvtsInseePerDate(__DIR__.'/mvtcommune2020.csv'); // lecture csv ds $mvtcoms et tri par ordre chrono
  //$mvtcoms = ['2019-01-01' => $mvtcoms['2019-01-01']];
  //$mvtcoms = ['2018-01-01' => $mvtcoms['2018-01-01']];
  $nbpatterns = 0;
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    foreach (GroupMvts::buildGroups($mvtcomsD) as $group) {
      $mod = $group->mod();
      if ($trace->is(['mod'=> $mod]))
        echo Yaml::dump(['$group'=> $group->asArray()], 3, 2);
      //if ($group->asArray()['mvts'][0]['avant']['id'] <> '49094') continue; // sélection pour debuggage
      $pattern = $group->mvtsPattern($trace);
      if ($trace->is(['mod'=> $mod]))
        echo '<b>',Yaml::dump(['$pattern'=> $pattern], 3, 2),"</b>\n";
      elseif (isset($pattern['ERREUR']) || isset($pattern['ALERTE']))
        echo '<b>',Yaml::dump(['$pattern'=> $pattern], 5, 2),"</b>\n";
      $patternWOEx = $pattern;
      unset($patternWOEx['example']);
      $key = json_encode($patternWOEx, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      if (!isset($patterns[$mod][$key])) {
        $patterns[$mod][$key] = $pattern;
        $nbpatterns++;
      }
      else
        $patterns[$mod][$key]['nb']++;
    }
  }
  ksort($patterns);
  {/* Affichage sous la forme d'une table, abandonné, le format de $patterns a été modifié
  echo count($patterns)," motifs trouvés\n";
  // Affichage de la liste des patterns
  echo "<table border=1>";
  //$trace = new Criteria([]); // full trace
  foreach ($patterns as $json => $pattern) {
    $example = $pattern['example'];
    unset($pattern['example']);
    $pattern['md5'] = md5($json);
    echo '<tr><td>',Yaml::dump(['<b>$pattern</b>'=> $pattern], 3, 2),"</td>\n";
    if (isset($_GET['example']))
      echo '<td>',Yaml::dump(['factAvExample'=> $example['factAv']], 7, 2),"</td>";
    echo "</tr>\n";
    // Nouvelle exécution de GroupMvts::mvtsPattern() éventuellement avec trace
    if ($trace->is(['mod'=> $mod])) {
      $group = $example['group'];
      echo Yaml::dump(['$group'=> $group->asArray()], 7, 2),"\n";
      $pattern = $group->mvtsPattern($trace);
    }
  }
  echo "</table>\n";
  */}
    
  $yaml = [
    'title'=> "liste des $nbpatterns motifs issus de GroupMvts::mvtsPattern()",
    'created'=> date(DATE_ATOM),
  ];
  echo Yaml::dump($yaml, 5, 2);
  foreach ($patterns as $mod => $patternMods) {
    if (!isset(GroupMvts::ModLabels[$mod])) {
      echo "mod$mod: MultiGroupMvts\n";
    }
    else {
      $yaml = [
        'label'=> GroupMvts::ModLabels[$mod],
        'nbpatterns'=> count($patternMods),
        'patterns'=> [],
      ];
      foreach ($patternMods as $json => $pattern) {
        $yaml['patterns'][] = [
          'nb'=> $pattern['nb'],
          'règles'=> $pattern['règles'],
          'example'=> [
            'factAv'=> $pattern['example']['factAv'],
            'group'=> $pattern['example']['group']->asArray(),
          ],
        ];
      }
      echo preg_replace('!-\n +!', '- ', Yaml::dump(["mod$mod" => $yaml], 5, 2));
    }
  }
  die();
}

if ($_GET['action'] == 'analyzeMod32') { // analyse des motifs de mouvements existants pour mod=32
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>mvtsPat32</title></head><body><pre>\n";
  $mvtcoms = GroupMvts::readMvtsInseePerDate(__DIR__.'/mvtcommune2020.csv'); // lecture csv ds $mvtcoms et tri par ordre chrono
  $nbpatterns = 0;
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    foreach (GroupMvts::buildGroups($mvtcomsD) as $group) {
      $mod = $group->mod();
      if ($mod <> '32') continue;
      $group->analyzeMod32();
    }
  }
  die();
}

{/*PhpDoc: screens
name: diffPatterns
title: diffPatterns - identif. des patterns périmés de patterns.yaml par comp. avec patterns-srce.yaml
doc: |
  patterns-srce.yaml correspond à une sortie de http://localhost/yamldoc/pub/rpicom/?action=mvtsPatterns
  patterns.yaml est commenté, et pour ne pas perdre ces commentaires, la présente cmde est utilisée
  en cas de modification de GroupMvts::factorAvant() ou de GroupMvts::mvtsPattern()
  J'utilise le champ md5 qui est le md5 du json_encode() du pattern produit par GroupMvts::mvtsPattern(),
  cette propriété a aussi été nommée id
*/}
if ($_GET['action'] == 'diffPatterns') { // identif. des patterns périmés de patterns.yaml par comp. avec patterns-srce.yaml
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>diffPatterns</title></head><body><pre>\n";
  $md5 = []; // liste des md5
  $srce = Yaml::parse(file_get_contents('patterns-srce.yaml'));
  //print_r($srce);
  foreach($srce['patterns'] as $pattern) {
    $md5[$pattern['md5']] = 1;
  }
  unset($srce);
  $patterns = Yaml::parse(file_get_contents('patterns.yaml'));
  //print_r($patterns);
  echo "<h2>Liste des id des patterns périmés dans patterns.yaml</h2>\n";
  foreach($patterns as $key => $patterns) {
    if (isset($patterns['mod'])) {
      //echo "$key reconnu comme sous-ensemble\n";
      foreach ($patterns['patterns'] as $pattern) {
        //echo '$pattern='; print_r($pattern);
        $id = $pattern['id'] ?? $pattern['md5'];
        if (!isset($md5[$id]))
          echo "$pattern[id]\n";
        else
          unset($md5[$id]);
      }
    }
  }
  echo "<h2>Liste des patterns patterns-srce.yaml absents de patterns.yaml</h2>\n";
  echo implode("\n", array_keys($md5)),"\n\n";
  $srce = Yaml::parse(file_get_contents('patterns-srce.yaml'));
  foreach($srce['patterns'] as $pattern) {
    if (isset($md5[$pattern['md5']]))
      echo Yaml::dump(['pattern'=> $pattern], 3, 2);
  }
  die();
}

{/*PhpDoc: screens
name: genEvols
title: genEvols - génération du fichier des évolutions et enregistrement d'un fichier d'état
doc: |
  Si ${datefin} est défini génère un fichier com${datefin}gen
*/}
if ($_GET['action'] == 'genEvols') { // génération du fichier des évolutions et enregistrement d'un fichier d'état
  //$datefin = '2000-01-01';
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>genEvols</title></head><body><pre>\n";
  //$trace = new Criteria([]); // aucun critère, tout est affiché
  $trace = new Criteria(['not']); // rien n'est affiché
  //$trace = new Criteria(['mod'=> ['not'=> ['10','20','21','30','31','33','34','41','50']]]);
  //$trace = new Criteria(['mod'=> ['not'=> ['10','21','31','20','30','41','33','34','50','32']]]); 
  //$trace = new Criteria(['mod'=> ['21']]); 

  $mvtcoms = GroupMvts::readMvtsInsee(__DIR__.'/mvtcommune2020.csv'); // lecture csv ds $mvtcoms et tri par ordre chrono
  $coms = new Base(__DIR__.'/com1943', $trace); // Lecture de com1943.yaml dans $coms
  //$mvtcoms = ['1976-01-01' => $mvtcoms['1976-01-01']]; // Test de mod=21
  //$mvtcoms = ['1990-02-01' => $mvtcoms['1990-02-01']]; // Test de aggrMvtsCom()
  //$mvtcoms = ['2020-01-01' => $mvtcoms['2020-01-01']]; // Test de mod=70
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    if (isset($datefin) && (strcmp($date_eff, $datefin) > 0)) {
      $coms->writeAsYaml(
          __DIR__."/com${datefin}gen",
          [
            'title'=> "Fichier des communes reconstitué au $datefin à partir de l'état au 1/1/1943 et des évolutions",
            'created'=> date(DATE_ATOM),
          ]
      );
      die("Fin sur date_eff=$date_eff\n");
    }
    foreach($mvtcomsD as $mod => $mvtcomsDM) {
      $coms->setTraceVar('mod', $mod);
      if (0 && $trace->is(['mod'=> $mod]))
        echo Yaml::dump(['$mvtcomsDM'=> $mvtcomsDM], 3, 2);
      foreach (GroupMvts::buildGroups($mvtcomsDM) as $group) {
        if ($trace->is(['mod'=> $mod]))
          echo Yaml::dump(['$group'=> $group->asArray()], 3, 2);
        $evol = $group->buildEvol($coms, $trace);
        if (1 || $trace->is(['mod'=> $mod]))
          echo '<b>',Yaml::dump(['$evol'=> $evol], 3, 2),"</b>\n";
        elseif (isset($evol['ERREUR']) || isset($evol['ALERTE']))
          echo '<b>',Yaml::dump(['$evol'=> $evol], 5, 2),"</b>\n";
      }
    }
  }
  die();
}

if ($_GET['action'] == 'buildState') { // affichage Yaml de l'état des communes par traduction du fichier INSEE
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>buildState $_GET[file]</title></head><body>\n",
         "<h3>lecture du fichier $_GET[file]</h3><pre>\n";
  //die("Fin ligne ".__LINE__);
  $coms = []; // [cinsee => record + children] 
  $enfants = []; // [cinsee => record] 
  if (!($file = @fopen($_GET['file'], 'r')))
    die("Erreur sur l'ouverture du fichier '$_GET[file]'\n");
  $sep = $_GET['format'] == 'csv' ? ',' : "\t";
  $headers = fgetcsv($file, 0, $sep);
  // un des fichiers comporte des caractères parasites au début ce qui perturbe la détection des headers
  foreach ($headers as $i => $header)
    if (preg_match('!"([^"]+)"!', $header, $matches))
      $headers[$i] = $matches[1];
  //echo "<pre>headers="; print_r($headers); echo "</pre>\n";
  while($record = fgetcsv($file, 0, $sep)) {
    //echo "<pre>record="; print_r($record); echo "</pre>\n";
    $rec = [];
    foreach ($headers as $i => $header) {
      $rec[strtolower($header)] = $_GET['format'] == 'csv' ?
          $record[$i] :
            mb_convert_encoding ($record[$i], 'UTF-8', 'Windows-1252');
    }
    if ($_GET['format'] == 'txt') {
      $rec = conv2Csv($rec);
      if ($rec['typecom'] == 'X')
        continue;
    }
    //if ($rec['com'] == '45307') { echo "<pre>rec="; print_r($rec); echo "</pre>\n"; }
    //echo "$rec[nccenr] ($typecom $rec[com])<br>\n";
    if (!$rec['comparent']) {
      //$coms[$rec['com']] = ['name'=> $rec['nccenr']];
      $coms[$rec['com']] = ['name'=> $rec['libelle']];
    }
    else {
      $enfants[$rec['com']] = $rec;
    }
    //if ($nbrec >= 10) die("<b>die nbrec >= 10</b>");
  }
  foreach ($enfants as $c => $enfant) {
    $comparent = $enfant['comparent'];
    if ($enfant['typecom'] == 'COMA')
      $childrenTag = ['aPourAssociées','estAssociéeA']; 
    elseif ($enfant['typecom'] == 'COMD')
      $childrenTag = ['aPourDéléguées', 'estDéléguéeDe']; 
    elseif ($enfant['typecom'] == 'ARM')
      $childrenTag = ['aPourArrondissementsMunicipaux', 'estArrondissementMunicipalDe']; 
    $coms[$comparent][$childrenTag[0]][$c] = ['name'=> $enfant['libelle']];
    if ($c <> $comparent)
      $coms[$c] = [$childrenTag[1] => $comparent];
  }
  ksort($coms);
  // Post-traitement - suppr. de 2 rétablisements ambigus c^té INSEE et contredits par IGN et wikipédia
  if ($_GET['state'] == '2020-01-01') {
    // suppr. du rétablisement de 14114 Bures-sur-Dives comme c. assciée de 14712
    unset($coms['14114']);
    unset($coms['14712']['aPourAssociées']);
    // suppr. du rétablisement de Gonaincourt comme c. déléguée de 52064
    unset($coms['52224']);
    unset($coms['52064']['aPourDéléguées']['52224']);
  }
  if (0) { // post-traitement - suppression des communes simples ayant uniquement un nom
    foreach ($coms as $c => $com) {
      if (isset($com['name']) && (count(array_keys($com))==1))
        unset($coms[$c]);
    }
  }
  $buildNameAdministrativeArea = <<<'EOT'
    if (isset($item['name']))
      return "$item[name] ($skey)";
    elseif (isset($item['estAssociéeA']))
      return "$skey estAssociéeA $item[estAssociéeA]";
    elseif (isset($item['estDéléguéeDe']))
      return "$skey estDéléguéeDe $item[estDéléguéeDe]";
    elseif (isset($item['estArrondissementMunicipalDe']))
      return "$skey estArrondissementMunicipalDe $item[estArrondissementMunicipalDe]";
    else
      return "none";
EOT;
  echo Yaml::dump([
      'title'=> "Fichier des communes au $_GET[state] avec entrée par code INSEE des communes associées ou déléguées et des ardt. mun.",
      'created'=> date(DATE_ATOM),
      'source'=> "création par traduction du fichier $_GET[file] de l'INSEE  \n"
."en utilisant la commande 'index.php ".implode(' ', $_GET)."'\n",
      '$schema'=> 'http://id.georef.eu/rpicom/exfcoms/$schema',
      'ydADscrBhv'=> [
        'jsonLdContext'=> 'http://schema.org',
        'firstLevelType'=> 'AdministrativeArea',
        'buildName'=> [ # définition de l'affichage réduit par type d'objet, code Php par type
          'AdministrativeArea'=> $buildNameAdministrativeArea,
        ],
        'writePserReally'=> true,
      ],
      'contents'=> $coms
    ], 99, 2);
  die();
}

{/*PhpDoc: functions
name: readfiles
title: function readfiles($dir, $recursive=false) - Lecture des fichiers locaux du répertoire $dir
doc: |
  Le système d'exploitation utilise ISO 8859-1, toutes les données sont gérées en UTF-8
  Si recursive est true alors renvoie l'arbre
*/}
function readfiles($dir, $recursive=false) { // lecture du nom, du type et de la date de modif des fichiers d'un rép.
  if (!$dh = opendir(utf8_decode($dir)))
    die("Ouverture de $dir impossible");
  $files = [];
  while (($filename = readdir($dh)) !== false) {
    if (in_array($filename, ['.','..']))
      continue;
    $filetype = filetype(utf8_decode($dir).'/'.$filename);
    $file = [
      'name'=>utf8_encode($filename),
      'type'=>$filetype, 
      'mdate'=>date ("Y-m-d H:i:s", filemtime(utf8_decode($dir).'/'.$filename))
    ];
    if (($filetype=='dir') && $recursive)
      $file['content'] = readfiles($dir.'/'.utf8_encode($filename), $recursive);
    $files[$file['name']] = $file;
  }
  closedir($dh);
  return $files;
}

{/*PhpDoc: functions
name: readfiles
title: "function union_keys(array $a, array $b): array - renvoie l'union des clés de $a et $b, en gardant d'abord l'ordre du + long et en ajoutant à la fin celles du + court"
*/}
function union_keys(array $a, array $b): array {
  // $a est considéré comme le + long, si non on inverse
  if (count($b) > count($a))
    return union_keys($b, $a);
  // j'ajoute à la fin de $a les clés de $b absentes de $a
  foreach (array_keys($b) as $kb) {
    if (!array_key_exists($kb, $a))
      $a[$kb] = 1;
  }
  return array_keys($a);
}
if (0) { // Test de union_keys()
  echo '<pre>';
  echo "arrays identiques\n";
  print_r(union_keys(
    ['c'=>1,'b'=>1,'a'=>1,'g'=>1,'u'=>1,'d'=>1],
    ['c'=>1,'b'=>1,'a'=>1,'g'=>1,'u'=>1,'d'=>1]
  ));
  echo "zyx identiques\n";
  print_r(union_keys(
    ['z'=>1,'y'=>1,'x'=>1,'g'=>1,'u'=>1,'d'=>1],
    ['z'=>1,'y'=>1,'x'=>1,'d'=>1,'u'=>1]
  ));
  print_r(union_keys(
    ['a'=>1,'b'=>1,'c'=>1,'g'=>1,'u'=>1,'d'=>1],
    ['x'=>1,'b'=>1,'c'=>1,'d'=>1,'u'=>1]
  ));
  die("Fin Test de union_keys");
}

// fonction utilisé par compareYaml() pour afficher un élément d'un des 2 fichiers
function compareShowOneElt(string $key, array $file, array $keypath, string $filepath): void {
  echo '<td>';
  if (!array_key_exists($key, $file))
    echo "<i>non défini</i>";
  elseif (is_null($file[$key]))
    echo "<i>null</i>";
  elseif (is_scalar($file[$key]))
    echo $file[$key];
  elseif (is_array($file[$key])
    && (strlen($json = json_encode($file[$key], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) < 80))
      echo "<code>$json</code>";
  else {
    $href = "?action=ypath&amp;file=$filepath&amp;ypath=".urlencode(implode('/',array_merge($keypath,[$key])));
    echo "<b>type <a href='$href'>",gettype($file[$key]),"</a></b>";
  }
  echo '</td>';
}

{/*PhpDoc: functions
name: compareYaml
title: "function compareYaml(array $file1, array $file2, callable $fneq=null, array $path=[], array $stat=['diff'=>0,'tot'=>0]): array - compare 2 tableaux correspondant à des fichiers Yaml"
doc: |
  Fonction récursive sur les noeuds des arbres.
  Chaque appel doit afficher 0, 1 ou plusieurs lignes du tableau comparatif.
  Prend des stats en entrée, les modifie et les renvoie à la fin.
*/}
function compareYaml(array $file1, array $file2, callable $fneq=null, array $path=[], array $stat=['diff'=>0,'tot'=>0]): array {
  if (!$path) {
    echo "<table border=1><th></th><th>$_GET[file1]</th><th>$_GET[file2]</th>\n";
  }
  foreach (union_keys($file1, $file2) as $key) {
    if (isset($file1[$key]) && is_array($file1[$key]) && isset($file2[$key]) && is_array($file2[$key])) {
      $stat = compareYaml($file1[$key], $file2[$key], $fneq, array_merge($path, [$key]), $stat);
    }
    else {
      // si soit n'existe que d'un des 2 côtés soit sont différents
      if (!array_key_exists($key, $file1) || !array_key_exists($key, $file2)
          || (is_null($fneq) ? ($file1[$key] <> $file2[$key]) : !$fneq($file1[$key], $file2[$key]))) {
        // A partir d'ici je sais que j'affiche une ligne et une seule
        echo '<tr><td>',implode('/', array_merge($path,[$key])),"</td>"; // affichage de la clé
        // affichage de file1
        compareShowOneElt($key, $file1, $path, $_GET['file1']);
        // affichage de file2
        compareShowOneElt($key, $file2, $path, $_GET['file2']);
        echo "</tr>\n";
        $stat['diff']++;
      }
      $stat['tot']++;
    }
  }
  if (!$path) {
    echo "</table>\n";
    printf("%d/%d différents soit %.2f %%<br>\n", $stat['diff'], $stat['tot'], $stat['diff']/$stat['tot']*100);
  }
  return $stat;
}
if (0) { // Test de la fonction compareYaml() 
  $doc1 = [
    'title'=> 'doc1',
    'idem'=> 'contenu identique',
    'zdiff'=> 'contenu différent 1',
    'ssdoc'=> [
      'idem'=> 'contenu identique',
      'diff'=> 'contenu différent 1',
      'null1'=> null,
      'tab'=> ['a','b'],
    ],
    'ssdoc2'=> [
      't'=> 'ssdoc2',
      'x'=> 'b',
    ],
  ];
  $doc2 = [
    'title'=> 'doc2',
    'idem'=> 'contenu identique',
    'tab'=> ['a','b'],
    'zdiff'=> 'contenu différent 2',
    'ssdoc'=> [
      'idem'=> 'contenu identique',
      'diff'=> 'contenu différent 2',
      'null1'=> "non null dans doc2",
      'null2'=> null,
    ],
    'ssdoc2'=> [
      't'=> 'ssdoc2',
      'x'=> 'b',
      'y'=> 'y',
      'b'=> 'b',
    ],
  ];
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>test compare</title></head><body>\n";
  $_GET = ['file1'=>'file1', 'file2'=> 'file2'];
  compareYaml($doc1, $doc2);
  die("Fin test compareYaml()");
}

{/*PhpDoc: functions
name: fneqArticle
title: "function fneqArticle(?string $a, ?string $b): bool - Test si 2 noms de communes sont identiques"
doc: |
  Remplace les multiples blancs par un blanc simple
  Supprime éventuellement l'article en début du nom
*/}
function fneqArticle(?string $a, ?string $b): bool { // Test si 2 noms de communes sont identiques
  static $articles = ['Le ','La ','Les ',"L'"];
  $a = str_replace('  ', ' ', $a);
  $b = str_replace('  ', ' ', $b);
  if ($a == $b)
    return true;
  if (strlen($a) < strlen($b))
    return fneqArticle($b, $a);
  foreach ($articles as $article) {
    if (substr($a, 0, strlen($article))==$article) {
      return (substr($a, strlen($article)) == $b);
    }
  }
  return false;
}
if (0) { // Test de fneqArticle()
  foreach([
    ['aaa', 'aaa'],
    ['aaa', 'bbb'],
    ['aaa', 'Le aaa'],
    ['Le aaa', 'aaa'],
  ] as $strs)
    echo "$strs[0] == $strs[1] ? ",fneqArticle($strs[0], $strs[1]) ? 'vrai' : 'faux',"<br>\n";
  die("Fin test de fneqArticle()");
}

{/*PhpDoc: screens
name: compare
title: compare - comparaison de 2 fichiers Yaml et affichage d'un tableau de comparaison
doc: |
  Les 2 fichiers à comparer doivent être sélectionnés interactivement.
  Le résultat est un tableau Html avec une ligne par feuille différente
  Utilise la fonction compareYaml()
*/}
if ($_GET['action'] == 'compare') { // comparaison de 2 fichiers et affichage d'un tableau de comparaison
  if (!isset($_GET['file1'])) {
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>compare</title></head><body>\n";
    echo "<b>Choix du premier fichier</b><br>\n";
    $files = readfiles(__DIR__);
    ksort($files);
    //echo "<pre>files="; print_r($files);
    foreach ($files as $name => $file) {
      $ext = substr($name, strrpos($name, '.') + 1);
      if ($ext == 'yaml')
        echo "<a href='?action=$_GET[action]&amp;file1=",urlencode($name),"'>$name</a><br>\n";
    }
    die();
  }
  if (!isset($_GET['file2'])) {
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>compare</title></head><body>\n";
    echo "<b>Choix du deuxième fichier</b><br>\n";
    $files = readfiles(__DIR__);
    ksort($files);
    foreach ($files as $name => $file) {
      $ext = substr($name, strrpos($name, '.') + 1);
      if (($ext == 'yaml') && ($name <> $_GET['file1']))
        echo "<a href='?action=$_GET[action]&amp;file1=",urlencode($_GET['file1']),
          "&amp;file2=",urlencode($name),"'>$name</a><br>\n";
    }
    die();
  }
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>compare $_GET[file1] $_GET[file2]</title></head><body>\n";
  echo "<b>Comparaison de $_GET[file1] et $_GET[file2]</b><br>\n";
  $file1 = Yaml::parse(file_get_contents(__DIR__."/$_GET[file1]"));
  $file2 = Yaml::parse(file_get_contents(__DIR__."/$_GET[file2]"));
  compareYaml($file1, $file2, 'fneqArticle');
  die("Fin de compare");
}

function ypath(array $yaml, array $path) {
  if (!$path)
    return $yaml;
  else {
    $key0 = array_shift($path);
    return ypath($yaml[$key0], $path);
  }
}

if ($_GET['action'] == 'ypath') {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>ypath $_GET[file] $_GET[ypath]</title></head><body><pre>\n";
  echo Yaml::dump(
      [ $_GET['ypath']
          => ypath(
            Yaml::parse(file_get_contents(__DIR__."/$_GET[file]")),
            explode('/', $_GET['ypath'])
          )
      ],
      99, 2);
  die();
}

// Teste un présupposé de brpicom qui est qu'une commune ne peut subire qu'un seul GroupMvts à une date donnée
if ($_GET['action'] == 'test_hypothèse_brpicom') {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>test_hypothèse_rpicom</title></head><body><pre>\n";
  $mvtcoms = GroupMvts::readMvtsInsee(__DIR__.'/mvtcommune2020.csv'); // lecture csv ds $mvtcoms
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    $ids = []; // ids impactés par un GroupMvts ss la forme [{id} => {groupe}]
    foreach($mvtcomsD as $mod => $mvtcomsDM) {
      foreach (GroupMvts::buildGroups($mvtcomsDM) as $group) {
        $gpids = $group->ids();
        foreach ($gpids as $id) {
          if (isset($ids[$id])) {
            echo "id $id dans $group et ",$ids[$id],"\n";
          }
          $ids[$id] = $group;
        }
      }
    }
  }
  die("Fin test_hypothèse_rpicom\n");
}

require_once __DIR__.'/rpicom.inc.php';

// Initialisation du RPICOM avec les communes du 1/1/2020 comme 'now'
function initRpicomFrom(string $compath, Criteria $trace): Base {
  // code Php intégré dans le document pour définir l'affichage résumé de la commune
  $buildNameAdministrativeArea = <<<'EOT'
if (isset($item['now']['name']))
  return $item['now']['name']." ($skey)";
else
  return '<s>'.array_values($item)[0]['name']." ($skey)</s>";
EOT;
  $rpicom = [
    'title'=> "Référentiel rpicom",
    'description'=> "Voir la documentation sur https://github.com/benoitdavidfr/yamldocs/tree/master/rpicom",
    'created'=> date(DATE_ATOM),
    'valid'=> '2020-01-01',
    '$schema'=> 'http://id.georef.eu/rpicom/exrpicom/$schema',
    'ydADscrBhv'=> [
      'jsonLdContext'=> 'http://schema.org',
      'firstLevelType'=> 'AdministrativeArea',
      'buildName'=> [ // définition de l'affichage réduit par type d'objet, code Php par type
        'AdministrativeArea'=> $buildNameAdministrativeArea,
      ],
      'writePserReally'=> true,
    ],
    'contents'=> [],
  ];
  $rpicom = new Base($rpicom, $trace);
  $coms = new Base($compath, new Criteria(['not'])); // Lecture de com20200101.yaml dans $coms
  foreach ($coms->contents() as $idS => $comS) {
    //echo Yaml::dump([$id => $com]);
    if (!isset($comS['name'])) continue;
    foreach ($comS['aPourAssociées'] ?? [] as $id => $com) {
      $rpicom->$id = ['now'=> [
        'name'=> $com['name'],
        'estAssociéeA'=> $idS,
      ]];
    }
    unset($comS['aPourAssociées']);
    foreach ($comS['aPourDéléguées'] ?? [] as $id => $com) {
      if ($id <> $idS)
        $rpicom->$id = ['now'=> [
          'name'=> $com['name'],
          'estDéléguéeDe'=> $idS,
        ]];
      else
        $comS['commeDéléguée'] = ['name'=> $com['name']];
    }
    unset($comS['aPourDéléguées']);
    foreach ($comS['aPourArrondissementsMunicipaux'] ?? [] as $id => $com) {
      $rpicom->$id = ['now'=> [
        'name'=> $com['name'],
        'estArrondissementMunicipalDe'=> $idS,
      ]];
    }
    unset($comS['aPourArrondissementsMunicipaux']);
    $rpicom->$idS = ['now'=> $comS];
    unset($coms->$idS);
  }
  unset($coms);
  return $rpicom;
}

{/*PhpDoc: screens
name: brpicom1
title: brpicom1 - construction du Rpicom v1
doc: |
  v1 de la construction Rpicom fondée sur initRpicomFrom() + GroupMvts::addToRpicom()
  En raison du nbre de motifs (206) de factorAvant() le code de GroupMvts::addToRpicom() est trop complexe et buggué
  J'abandonne le 19/4/2020 19:00 cette solution pour tester une factorisation sur après
*/}
if ($_GET['action'] == 'brpicom') { // construction du Rpicom v2
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>brpicom</title></head><body><pre>\n";
  //$trace = new Criteria([]); // aucun critère, tout est affiché
  $trace = new Criteria(['not']); // rien n'est affiché
  //$trace = new Criteria(['mod'=> ['32']]);
  //$trace = new Criteria(['mod'=> ['not'=> ['10','21','31','20','30','41','33','34','50','32']]]); 

  // fabrication de la version initiale du RPICOM avec les communes du 1/1/2020 comme 'now'
  $rpicoms = initRpicomFrom(__DIR__.'/com20200101', new Criteria(['not']));
  
  $mvtcoms = GroupMvts::readMvtsInseePerDate(__DIR__.'/mvtcommune2020.csv'); // lecture csv ds $mvtcoms
  krsort($mvtcoms); // tri par ordre chrono inverse
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    foreach (GroupMvts::buildGroups($mvtcomsD) as $group) {
      $group = $group->factAvDefact();
      if ($trace->is(['mod'=> $group->mod()]))
        echo Yaml::dump(['$group'=> $group->asArray()], 3, 2);
      $group->addToRpicom($rpicoms, $trace);
    }
  }
  // Post-traitements
  if (0)
    $rpicoms->startExtractAsYaml();
  foreach ($rpicoms->contents() as $id => $rpicom) { // Post-traitements no 1 - remplacer les unknown et 2 - Mayotte
    // Post-traitement no 1 pour remplacer les associées unknown à partir des associations effectuées précédemment
    // + idem pour les déléguées
    $unknownAssosVdate = null;
    $unknownDelegVdate = null;
    foreach ($rpicom as $datev => $vcom) {
      if ($unknownAssosVdate && isset($vcom['évènement']['sAssocieA'])) {
        $rpicom[$unknownAssosVdate]['estAssociéeA'] = $vcom['évènement']['sAssocieA'];
        $rpicoms->$id = $rpicom;
        $unknownAssosVdate = null;
      }
      if (isset($vcom['estAssociéeA']) && ($vcom['estAssociéeA'] == 'unknown')) {
        $unknownAssosVdate = $datev;
      }
      if ($unknownDelegVdate && isset($vcom['évènement']['devientDéléguéeDe'])) {
        $rpicom[$unknownDelegVdate]['estDéléguéeDe'] = $vcom['évènement']['devientDéléguéeDe'];
        $rpicoms->$id = $rpicom;
        $unknownDelegVdate = null;
      }
      if (isset($vcom['estDéléguéeDe']) && ($vcom['estDéléguéeDe'] == 'unknown')) {
        $unknownDelegVdate = $datev;
      }
    }
    // Post-traitement no 2 pour indiquer que Mayote est devenu DOM le 31 mars 2011
    if (substr($id, 0, 3) == '976') {
      $rpicom['2011-03-31'] = ['évènement' => "Entre dans le périmètre du Rpicom"];
      $rpicoms->$id = $rpicom;
    }
  }
  // Post-traitement no 3 pour indiquer que Saint-Martin et Saint-Barthélemy ont été DOM jusqu'au 15 juillet 2007
  foreach (['97123'=> "Saint-Barthélemy", '97127'=> "Saint-Martin"] as $id => $name) {
    $rpicoms->$id = [
      '2007-07-15' => [
        'évènement' => "Sort du périmètre du Rpicom",
        'name'=> $name,
      ]
    ];
  }
  // Post-traitement no 4 - suppression du rétablisst de 14114 Bures-sur-Dives ambigue sur site INSEE, contredit par IGN et wikipédia
  if (1) {
    echo "Suppression du rétablisst de 14114 Bures-sur-Dives ambigue sur site INSEE, contredit par IGN et wikipédia\n";
    $id = '14114';
    $rpicom = $rpicoms->$id;
    unset($rpicom['now']);
    unset($rpicom['2019-12-31']);
    $rpicoms->$id = $rpicom;
  }
  
  if (0)
    $rpicoms->showExtractAsYaml(5, 2);
  $rpicoms->ksort(); // tri du Yaml sur le code INSEE de commune
  $rpicoms->writeAsYaml('rpicom');
  die("Fin brpicom ok, rpicom sauvé dans rpicom.yaml\n");
}

// dénombrement des communes simples dans le fichier
function compteFrom(string $compath, Criteria $trace): int {
  $coms = new Base($compath, new Criteria(['not'])); // Lecture de com20200101.yaml dans $coms
  $nbComS = 0;
  foreach ($coms->contents() as $idS => $comS) {
    if (isset($comS['name'])) $nbComS++;
  }
  //echo "nbComS=$nbComS\n";
  return $nbComS;
}

// ajoute $cI à $cT ts les 2 en fmt [ {date} => ['T'=> nbrTotal, '+'=> nbrAjout, '-'=> nbrSuppr, 'M'=> nbrModif] ]
function ajoutComptes(array $cT, array $cI): array {
  if (!$cI) return $cT;
  foreach ($cI as $date => $cidate) {
    foreach ($cidate as $k => $v) {
      if (isset($cT[$date][$k]))
        $cT[$date][$k] += $v;
      else
        $cT[$date][$k] = $v;
    }
  }
  return $cT;
}

if ($_GET['action'] == 'nombres') { // dénombrement
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>dénombrement</title></head><body><pre>\n";
  //$trace = new Criteria([]); // aucun critère, tout est affiché
  $trace = new Criteria(['not']); // rien n'est affiché
  //$trace = new Criteria(['mod'=> ['not'=> ['34','70','21','10','41','32','50','33','31','20','30']]]); 

  $comptes = []; // [ {date} => ['T'=> nbre total, '+'=> nbre ajoutées, '-'=> nbre supprimées, 'M'=> nbre modifiées] ];
  $comptes['2020-01-01']['T'] = compteFrom(__DIR__.'/com20200101', new Criteria(['not']));
  $comptes['2019-01-01']['T'] = compteFrom(__DIR__.'/com20190101', new Criteria(['not']));
  $comptes['2018-01-01']['T'] = compteFrom(__DIR__.'/com20180101', new Criteria(['not']));
  $comptes['2017-01-01']['T'] = compteFrom(__DIR__.'/com20170101', new Criteria(['not']));
  $comptes['2010-01-01']['T'] = compteFrom(__DIR__.'/com20100101', new Criteria(['not']));
  $comptes['2000-01-01']['T'] = compteFrom(__DIR__.'/com20000101', new Criteria(['not']));
  $comptes['1943-01-01']['T'] = compteFrom(__DIR__.'/com19430101', new Criteria(['not']));
  
  $mvtcoms = GroupMvts::readMvtsInseePerDate(__DIR__.'/mvtcommune2020.csv'); // lecture csv ds $mvtcoms
  krsort($mvtcoms); // tri par ordre chrono inverse
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    foreach (GroupMvts::buildGroups($mvtcomsD) as $group) {
      $mod = $group->mod();
      $group = $group->factAvDefact();
      $comptGrpe = $group->compte($trace);
      if ($trace->is(['mod'=> $mod]))
        echo Yaml::dump(['$comptGrpe'=> $comptGrpe]);
      $comptes = ajoutComptes($comptes, $comptGrpe);
      if ($trace->is(['mod'=> $mod]))
        echo Yaml::dump(['$comptes'=> $comptes]);
    }
  }
  //print_r($comptes);
  $comptesParAnnee = [];
  foreach ($comptes as $date => $compteD) {
    preg_match('!^(\d\d\d\d)-(\d\d-\d\d)$!', $date, $matches);
    if ($matches[2] == '01-01')
      $annee = $date;
    else
      $annee = "$matches[1]-Z";
    $comptesParAnnee = ajoutComptes($comptesParAnnee, [$annee => $compteD]);
  }
  //print_r($comptesParAnnee);
  krsort($comptesParAnnee);
  $comments = [
    '2020-01-01'=> "Limitation du nbre de modifications en raison des élections municipales de 2020",
    '2019-01-01'=> "Incitations financières à la création de communes nouvelles",
    '2017-01-01'=> "Incitations financières à la création de communes nouvelles",
    '2016-Z'=> "Incitations financières à la création de communes nouvelles",
    '2016-01-01'=> "Incitations financières à la création de communes nouvelles",
    '2015-Z'=> "Incitations financières à la création de communes nouvelles",
    '2015-01-01'=> "Incitations financières à la création de communes nouvelles",
    '2010-Z'=> "Entrée dans le Rpicom le 31 mars 2011 des 17 communes de Mayotte",
    '2007-Z'=> "Sortie du Rpicom le 15 juillet 2007 de Saint-Barthélemy et de Saint-Martin",
    '1976-01-01'=> "Bi-départementalisation de la Corse",
    '1968-01-01'=> "Création des départements 91, 92, 93, 94 et 95",
  ];
  $comModif['2007-Z'] = -2;
  $comModif['2010-Z'] = 17;
  $headers = ['date','T','+','-','CD','M',"T'",'commentaire'];
  if (1) { // en html
    echo "</pre><table border=1>\n","<th>",implode('</th><th>', $headers),"</th>\n";
    foreach ($comptesParAnnee as $annee => $ca) {
      if (isset($ca['T']))
        $total = $ca['T'];
      $total -= ($ca['+'] ?? 0) - ($ca['-'] ?? 0) + ($comModif[$annee] ?? 0);
      echo "<tr><td>$annee</td><td>",$ca['T'] ?? '',"</td>",
        "<td>",$ca['+'] ?? '',"</td><td>",$ca['-'] ?? '',"</td><td>",$ca['CD'] ?? '',"</td>",
        "<td>",$ca['M'] ?? '',"</td>",
        "<td>$total</td><td>",$comments[$annee] ?? '',"</td></tr>\n";
    }
    echo "</table><pre>\n";
  }
  if (1) { // en Markdown
    echo "<h2>Markdown</h2>\n";
    $headers = ['date','T','+','-','CD','M',"T'",'commentaire'];
    echo "| ",implode(' | ', $headers)," |\n";
    foreach ($headers as $header) echo "| - "; echo "|\n";
    foreach ($comptesParAnnee as $annee => $ca) {
      if (isset($ca['T']))
        $total = $ca['T'];
      $total -= ($ca['+'] ?? 0) - ($ca['-'] ?? 0);
      echo "| $annee | ",$ca['T'] ?? '',
        " | ",$ca['+'] ?? ''," | ",$ca['-'] ?? ''," | ",$ca['CD'] ?? '',
        " | ",$ca['M'] ?? '',
        " | ",$total," | ",$comments[$annee] ?? '',"|\n";
    }
  }
  die("\nFin nombres ok\n");
}

{/*PhpDoc: screens
name: patterns
title: patterns - listage des motifs gMvtsP - ABANDONNE
doc: |
  v2 de la construction Rpicom fondée sur initRpicomFrom() + GroupMvts::xx()
  Nlle solution démarrée le 19/4/2020 19:00
  Je démarre par le litage des motifs. Je n'arrive pas à les interpréter !!!
*/}
if ($_GET['action'] == 'patterns') { // listage des motifs gMvtsP - ABANDONNE 
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>patterns</title></head><body><pre>\n";

  // définition de la classe GMvtsP
  require_once __DIR__.'/gmvtsp.inc.php';

  $trace = new Criteria([]); // aucun critère, tout est affiché
  $trace = new Criteria(['not']); // rien n'est affiché
  //$trace = new Criteria(['mod'=> ['not'=> ['10','20','21','30','31','33','34','41','50']]]);
  //$trace = new Criteria(['mod'=> ['not'=> ['10','21','31','20','30','41','33','34','50','32']]]); 
  //$trace = new Criteria(['mod'=> ['21']]); 
  
  $mvtcoms = GroupMvts::readMvtsInsee(__DIR__.'/mvtcommune2020.csv'); // lecture csv ds $mvtcoms
  krsort($mvtcoms); // tri par ordre chrono inverse
  $nbpatterns = 0;
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    foreach($mvtcomsD as $mod => $mvtcomsDM) {
      foreach (GMvtsP::buildGroups($mvtcomsDM) as $group) {
        if ($trace->is(['mod'=> $mod]))
          echo Yaml::dump(['$group'=> $group->asArray()], 3, 2);
        $pattern = $group->pattern();
        if ($trace->is(['mod'=> $mod]))
          echo Yaml::dump(['$pattern'=> $pattern], 3, 2);
        $patternWOEx = $pattern;
        unset($patternWOEx['example']);
        $key = json_encode($patternWOEx, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        if (!isset($patterns[$mod][$key])) {
          $patterns[$mod][$key] = array_merge($pattern, ['nb'=> 1]);
          $nbpatterns++;
        }
        else
          $patterns[$mod][$key]['nb']++;
      }
    }
  }
  ksort($patterns);
  $yamls = [
    'title'=> "$nbpatterns motifs trouvés avec GMvtsP",
    'created'=> date(DATE_ATOM),
  ];
  foreach ($patterns as $mod => $patternMods) {
    $yamls['mod'.$mod]= [
      'label' => '',
      'patterns' => [],
    ];
    foreach ($patternMods as $key => $pattern) {
      $md5 = md5($key);
      $example = $pattern['example'];
      $yamls['mod'.$mod]['label'] = $pattern['label'];
      $yamls['mod'.$mod]['patterns'][] = [
        'nb'=> $pattern['nb'],
        'md5'=> $md5,
        'factAp'=> $pattern['factAp'],
        'example'=> $pattern['example'],
      ];
    }
  }
  echo Yaml::dump($yamls, 6, 2),"\n";
  die("Fin GMvtsP::patterns() ok\n");
}

if ($_GET['action'] == 'showGrpMvts') { // consultation des GroupMvts avec possibilité de sélection
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>viewGrpMvts</title></head><body>\n";
  echo "<form><table border=1><tr>\n",
  "<input type='hidden' name='action' value='$_GET[action]'>\n",
  "<td>mod:<input type='text' name='mod' size=8 value=",$_GET['mod'] ?? '',"></td>",
  "<td>date:<input type='text' name='date' size=10 value=",$_GET['date'] ?? '',"></td>",
  "<td>id:<input type='text' name='id' size=5 value=",$_GET['id'] ?? '',"></td>",
  "<td>name:<input type='text' name='name' size=20 value=",$_GET['name'] ?? '',"></td>",
  "<td><input type='submit'></td>",
  "</tr></table></form>\n";
    
  $mvtcoms = GroupMvts::readMvtsInseePerDate(__DIR__.'/mvtcommune2020.csv'); // lecture csv ds $mvtcoms
  krsort($mvtcoms); // tri par ordre chrono inverse
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    if (isset($_GET['date']) && (substr($date_eff, 0, strlen($_GET['date'])) <> $_GET['date']))
      continue;
    foreach (GroupMvts::buildGroups($mvtcomsD) as $group) {
      if (isset($_GET['mod']) && $_GET['mod']) {
        if (($_GET['mod'] == 'M')) {
          if (get_class($group) <> 'MultiGroupMvts')
            continue;
        }
        elseif (($group->mod() <> $_GET['mod']))
          continue;
      }
      if (isset($_GET['id']) && $_GET['id'] && !in_array($_GET['id'], $group->ids()))
        continue;
      if (isset($_GET['name']) && $_GET['name'] && !in_array($_GET['name'], $group->names()))
        continue;
      $group = $group->factAvDefact();
      $group->show();
    }
  }
  die();
}

if ($_GET['action'] == 'interpolRpicom') { // interpolation d'un état dans le Rpicom
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>interpolRpicom</title></head><body><pre>\n";
  $state = $_GET['state'];
  $rpicoms = new Base(__DIR__.'/rpicom');
  $coms = []; // l'état à la date
  foreach ($rpicoms->contents() as $id => $rpicom) {
    //if (!in_array($id, ['01283'])) continue;
    //echo Yaml::dump([$id => $rpicom], 7, 2);
    $vcom = interpolRpicom($rpicom, $state)['version'];
    $evt = array_shift($vcom);
    //echo Yaml::dump(["$id@$state" => [$evt, $vcom]]);
    if($vcom)
      $coms[$id] = $vcom;
  }
  // chgt de format
  /*foreach ($coms as $id => $com) {
    if (isset($com['estAssociéeA'])) {
      $coms[$com['estAssociéeA']]['aPourAssociées'][$id] = ['name'=> $com['name']];
      unset($com['name']);
    }
  }*/
  //echo Yaml::dump($coms); die();
  $fcoms = [
    'title'=> "Fichier interpolé au $state",
    'contents'=> $coms,
  ];
  $frefname = 'com'.str_replace('-','',$state);
  $fref = new base($frefname);
  $frefcoms = $fref->contents();
  // chgt de format - transfert des noms des communes rattachées stockés dans la commune de rattachement
  foreach ($frefcoms as $id => $com) {
    if (isset($com['estAssociéeA'])) // ajout du nom des communes associées
      $frefcoms[$id]['name'] = $frefcoms[$com['estAssociéeA']]['aPourAssociées'][$id]['name'];
    if (isset($com['estDéléguéeDe'])) // ajout du nom des communes déléguées
      $frefcoms[$id]['name'] = $frefcoms[$com['estDéléguéeDe']]['aPourDéléguées'][$id]['name'];
    if (isset($com['estArrondissementMunicipalDe'])) // ajout du nom des ARM
      $frefcoms[$id]['name'] =
          $frefcoms[$com['estArrondissementMunicipalDe']]['aPourArrondissementsMunicipaux'][$id]['name'];
  }
  foreach ($frefcoms as $id => $com) { // suppression des 'aPourAssociées', aPourDéléguées et aPourARM
    unset($frefcoms[$id]['aPourAssociées']);
    if (isset($com['aPourDéléguées'])) {
      foreach ($com['aPourDéléguées'] as $idd => $comd) {
        if ($idd == $id)
          $frefcoms[$id]['commeDéléguée'] = ['name'=> $comd['name']];
      }
    }
    unset($frefcoms[$id]['aPourDéléguées']);
    unset($frefcoms[$id]['aPourArrondissementsMunicipaux']);
  }
  $fref = [
    'title'=> "Fichier de référence $frefname",
    'contents'=> $frefcoms,
  ];
  $_GET['file1'] = "interpolé";
  $_GET['file2'] = "$frefname.yaml";
  echo "<h2>Comparaison</h2>\n";
  compareYaml($fcoms, $fref, 'fneqArticle');
  echo "<h2>Contenu du fichier interpolé</h2>\n";
  echo Yaml::dump($coms);
  die();
}

// transcode un code INSEE au moins valide à un instant en un code de C. simple valide à la date de validité du référentiel
function trcodeCSimple(string $id, array $rpicoms): string {
  $rpicom = $rpicoms[$id];
  // commune simple valide, pas de trancodage
  if (isset($rpicom['now']) && !isset($rpicom['now']['estAssociéeA']) && !isset($rpicom['now']['estDéléguéeDe']))
    return $id;
  if (isset($rpicom['now']['estAssociéeA'])) // entité associée existante
    return $rpicom['now']['estAssociéeA'];
  if (isset($rpicom['now']['estDéléguéeDe'])) // entité déléguée existante
    return $rpicom['now']['estDéléguéeDe'];
  // $id correspond à une entité périmée
  $evt = array_values($rpicom)[0]['évènement'];
  $evtLabel = is_array($evt) ? array_keys($evt)[0] : $evt; // soit le label soit la clé
  if (in_array($evtLabel, ['fusionneDans','sAssocieA','seFondDans','devientDéléguéeDe','quitteLeDépartementEtPrendLeCode']))
    return trcodeCSimple($evt[$evtLabel], $rpicoms);
  elseif ($evtLabel == 'seDissoutDans')
    return trcodeCSimple($evt['seDissoutDans'][0], $rpicoms);
  elseif ($evtLabel == 'Sort du périmètre du Rpicom')
    return '';
  else {
    echo "<tr><td>",Yaml::dump($rpicom),"</td></tr>\n";
  }
}

function trcodeERatt(string $id, array $rpicoms): string {
  $rpicom = $rpicoms[$id];
  // entité valide, pas de trancodage
  if (isset($rpicom['now']))
    return $id;
  // $id correspond à une entité périmée
  $evt = array_values($rpicom)[0]['évènement'];
  $evtLabel = is_array($evt) ? array_keys($evt)[0] : $evt; // soit le label soit la clé
  if (in_array($evtLabel, ['fusionneDans','seFondDans','quitteLeDépartementEtPrendLeCode']))
    return trcodeCSimple($evt[$evtLabel], $rpicoms);
  elseif ($evtLabel == 'seDissoutDans')
    return trcodeCSimple($evt['seDissoutDans'][0], $rpicoms);
  elseif ($evtLabel == 'Sort du périmètre du Rpicom')
    return '';
  else {
    echo "<tr><td>",Yaml::dump($rpicom),"</td></tr>\n";
  }
}

if ($_GET['action'] == 'transcode') { // production d'une table de transcodage
  $rpicoms = new Base(__DIR__.'/rpicom');
  $nbtr = 0;
  $headers = ['ancien','simple','rattachée'];
  if (php_sapi_name()<>'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>transcodage</title></head><body><pre>\n",
         "<table border=1><th>",implode('</th><th>', $headers),"</th>\n";
  else
    echo implode(';', $headers),"\n";
  foreach ($rpicoms->contents() as $id => $rpicom) {
    $csid = trcodeCSimple($id, $rpicoms->contents());
    if ($csid == $id)
      continue;
    $erid = trcodeERatt($id, $rpicoms->contents());
    if (php_sapi_name()<>'cli')
      echo "<tr><td>$id</td><td>$csid</td><td>$erid</td></tr>\n";
    else
      echo "$id;$csid;$erid\n";
    $nbtr++;
  }
  if (php_sapi_name()<>'cli')
    echo "</table>\n$nbtr lignes écrites\nfin transcodage\n";
  die();
}

require_once __DIR__.'/geojfile.inc.php';

// Lecture de AECOG2020 et comparaison avec le COG2020
if ($_GET['action'] == 'lectureAE') {
  /* il manque dans COMMUNE_CARTO 4 communes simples / INSEE
    title: 'Communes simples du COG absentes de AECOG'
    contents:
        22016: { name: Île-de-Bréhat }
        29083: { name: Île-de-Sein }
        29155: { name: Ouessant }
        85113: { name: 'L''Île-d''Yeu' }
  
     il manque dans ENTITE_RATTACHEE_CARTO / INSEE 1 c. associée et 1 c. déléguée pourlesquelles il y a ambigüité INSEE
    title: 'entités rattachées du COG2020 absentes de AECOG2020'
    contents:
        14114: { name: Bures-sur-Dives, estAssociéeDe: 14712 }
        52224: { name: Gonaincourt, estDéléguéeDe: 52064 }
  
   Il semble que chaque ENTITE_RATTACHEE_CARTO soit répétée 6 fois
  */
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>lectureAE</title></head><body><pre>\n";
  if (1) {
    // Lecture du COG 2020
    $coms = new Base(__DIR__.'/com20200101'); // coms contiendra la liste des communes simples
    $coms = $coms->contents();
    $crats = []; // entités rattachées
    foreach ($coms as $id => $com) {
      foreach ([
        'aPourDéléguées'=> 'estDéléguéeDe',
        'aPourAssociées'=> 'estAssociéeA',
        'aPourArrondissementsMunicipaux'=> 'estArrondissementMunicipalDe'
      ] as $aPourRat => $estRatDe) {
        // Suppression des entrées du fichier par entité rattachée
        if (isset($com[$estRatDe]))
          unset($coms[$id]);
        // Création des entités rattachées dans $crats
        foreach ($com[$aPourRat] ?? [] as $idrat => $crat)
          $crats[$idrat] = array_merge($crat, [$estRatDe => $id]);
      }
    }
    //echo '$coms = '; print_r($coms);
    //echo '$crats = '; print_r($crats); die("Fin ligne ".__LINE__);
  }
  
  echo "title: Lecture de AECOG dans $_GET[aepath]\n";
  echo "contents:\n";

  if (1) { // Lecture des communes d'AE et comparaison avec le COG
    $nbfeat = 0;
    $aecom = new GeoJFile("$_GET[aepath]/COMMUNE_CARTO.geojson");
    foreach ($aecom->quickReadFeatures() as $nofeat => $feature) {
      //print_r($feature);
      $prop = $feature['properties'];
      //print_r($prop);
      //echo "  $prop[INSEE_COM]:\n    name: $prop[NOM_COM]\n";
      $nbfeat++;
      if (!isset($coms[$prop['INSEE_COM']]))
        echo "  $prop[INSEE_COM]:\n    name: $prop[NOM_COM]\n    status: absent de l'INSEE\n";
      else
        unset($coms[$prop['INSEE_COM']]);
    }
    echo "$nbfeat objets lus\n";
    echo Yaml::dump([
      'title'=> "Communes simples du COG absentes de AECOG",
      'contents'=> $coms
    ]);
  }

  if (1) {
    $nbfeat = 0;
    $aerat = new GeoJFile("$_GET[aepath]/ENTITE_RATTACHEE_CARTO.geojson");
    $aerats = [];
    foreach ($aerat->quickReadFeatures() as $nofeat => $feature) {
      //print_r($feature);
      $prop = $feature['properties'];
      //print_r($prop);
      if (isset($aerats[$prop['INSEE_COM']]))
        $aerats[$prop['INSEE_COM']]['count']++;
      else {
        $aerats[$prop['INSEE_COM']] = array_merge($prop,['count'=> 1]);
        if (!isset($crats[$prop['INSEE_COM']]))
          echo "  $prop[INSEE_COM]:\n    name: $prop[NOM_COM]\n    status: absent de l'INSEE\n";
        else
          unset($crats[$prop['INSEE_COM']]);
      }
      //echo "  $prop[INSEE_COM]:\n    name: $prop[NOM_COM]\n    count: ",$aerats[$prop['INSEE_COM']]['count'],"\n";
      $nbfeat++;
    }
    echo "$nbfeat objets lus\n";
    echo Yaml::dump([
      'title'=> "entités rattachées du COG2020 absentes de AECOG2020",
      'contents'=> $crats
    ]);
  }
  die("eof:\n");
}

if ($_GET['action'] == 'lectureGeoFLA') {
  $description = "  - Ne contient pas les DOM\n"
      ."  - Contient les ARM et pas les 3 communes PLM\n"
      ."  - Manque 2 communes dans GéoFLA2011 / Rpicom2011:\n"
      ."    14472: {name: Notre-Dame-de-Fresnay}\n"
      ."    50649: {name: Pont-Farcy}\n";
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>lectureGeoFLA</title></head><body><pre>\n";
  $rpicoms = new Base(__DIR__.'/rpicom');
  $coms2011 = []; // l'état à la date
  foreach ($rpicoms->contents() as $id => $rpicom) {
    $vcom = interpolRpicom($rpicom, '2011-01-01')['version'];
    $evt = array_shift($vcom);
    //echo Yaml::dump(["$id@$state" => [$evt, $vcom]]);
    if ($vcom && !isset($vcom['estAssociéeA']) && (substr($id, 0, 2) <> '97'))
      $coms2011[$id] = $vcom;
  }
  //print_r($coms2011);
  echo count($coms2011)," communes issues de l'interpolation du Rpicom au 1/1/2011\n";

  if (0) { // Lecture du fichier sans utiliser la classe GeoJFile
    $file = fopen($_GET['file'], 'r');
    $buff = fgets($file); // {
    $buff = fgets($file); // "type": "FeatureCollection",
    $buff = fgets($file); // "name": "COMMUNE_CARTO",
    $buff = fgets($file); // "crs": { "type": "name", "properties": { "name": "urn:ogc:def:crs:OGC:1.3:CRS84" } },
    $buff = fgets($file); // "features": [
    echo "title: Lecture de $_GET[file]\n";
    echo "description: |\n",$description;
    echo "contents:\n";
    $nbfeat = 0;
    while ($buff = fgets($file)) {
      $buff = rtrim($buff);
      if ($buff == ']')
        break;
      if (substr($buff, -1) == ',')
        $buff = substr($buff, 0, -1); // supp de la , en fin de ligne
      $buff = mb_convert_encoding ($buff, 'UTF-8', 'Windows-1252');
      //echo $buff;
      $feature = json_decode($buff, true);
      //print_r($feature); die();
      $prop = $feature['properties'];
      //echo '$prop='; print_r($prop);
      //echo "  $prop[INSEE_COM]:\n    name: $prop[NOM_COMM]\n";
      $nbfeat++;
      if (!isset($coms2011[$prop['INSEE_COM']]))
        echo "  $prop[INSEE_COM]:\n    name: $prop[NOM_COMM]\n    status: absent de l'INSEE\n";
      else
        unset($coms2011[$prop['INSEE_COM']]);
    }
  }
  else { // Lecture du fichier en utilisant la classe GeoJFile
    echo "title: Lecture de $_GET[file]\n";
    echo "description: |\n",$description;
    echo "contents:\n";
    $geofla = new GeoJFile($_GET['file'], 'Windows-1252');
    $nbfeat = 0;
    foreach ($geofla->quickReadFeatures() as $feature) {
      $prop = $feature['properties'];
      $nbfeat++;
      if (!isset($coms2011[$prop['INSEE_COM']]))
        echo "  $prop[INSEE_COM]:\n    name: $prop[NOM_COMM]\n    status: absent de l'INSEE\n";
      else
        unset($coms2011[$prop['INSEE_COM']]);
    }
  }
  echo "$nbfeat objets lus dans GéoFLA2011\n";
  echo "Communes simples du Rpicom absentes de GéoFLA2011\n";
  print_r($coms2011);
  die("eof:\n");
}

class EvtType {
  /*
    type = 'Création' | 'Evol/Disparition'
    locModifier = 'Yes' | 'No' (abandonné)
  */
  const Libellés = [
    'Entre dans le périmètre du Rpicom'=> ['type'=> 'Création'],
  ];
  const ObjectKeys = [
    'rétablieCommeSimpleDe'=> ['type'=> 'Création'],
    'rétablieCommeAssociéeDe'=> ['type'=> 'Création'],
    'rétabliCommeArrondissementMunicipalDe'=> ['type'=> 'Création'],
    'crééeAPartirDe'=> ['type'=> 'Création'],
    'arriveDansLeDépartementAvecLeCode'=> ['type'=> 'Création'],
    'crééeParFusionSimpleDe'=> ['type'=> 'Création'],
  ];

  static function type($evt): array {
    if (is_string($evt)) {
      $type = self::Libellés[$evt]['type'] ?? 'Evol/Disparition';
      $locModifier = self::Libellés[$evt]['locModifier'] ?? 'Yes';
    }
    else {
      $objectKey = array_keys($evt)[0];
      $type = self::ObjectKeys[$objectKey]['type'] ?? 'Evol/Disparition';
      $locModifier = self::ObjectKeys[$objectKey]['locModifier'] ?? 'Yes';
    }
    return ['type'=> $type, 'locModifier'=> $locModifier];
  }
};

if ($_GET['action'] == 'geolocHisto') { // dénombrement
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>geolocHisto</title></head><body><pre>\n";
  $rpicoms = new Base(__DIR__.'/rpicom');
  $rpicoms = $rpicoms->contents();
  if (0) { // nbre d'entités supprimées 
    $nbrpicoms = count($rpicoms);
    foreach ($rpicoms as $id => $rpicom) {
      if (!isset($rpicom['now'])) {
        //echo Yaml::dump([$id => $rpicom], 3, 2);
        unset($rpicoms[$id]);
      }
    }
    printf("%d entités supprimées / %d soit %.2f %%\n",
      ($nbrpicoms-count($rpicoms)), $nbrpicoms, ($nbrpicoms-count($rpicoms))/$nbrpicoms * 100);
  }
  if (0) { // 46275 intervalles et 47286 versions pour 39162 entités soit 1.18 intervalles / entité
    $nbint = 0;
    $nbversions = 0;
    foreach ($rpicoms as $id => $rpicom) {
      $nbversions += count($rpicom);
      foreach ($rpicom as $dv => $version) {
        if (($dv == 'now') || (EvtType::type($version['évènement'])['type'] <> 'Création'))
          $nbint++;
      }
    }
    $nbentités = count($rpicoms);
    printf ("$nbint intervalles et $nbversions versions pour $nbentités entités soit %.2f intervalles / entité\n",
      $nbint / $nbentités); 
  }
  if (1) { // dénombrement des évènements autres que création
    {/* sortie:
      $evtLabels:
          'Absorbe certaines de ses c. rattachées ou certaines de ses c. associées deviennent déléguées': 151
          'Commune associée rétablie comme commune simple': 239
          'Commune déléguée rétablie comme commune simple': 2
          'Commune rattachée devient commune de rattachement': 2
          'Commune rétablissant des c. rattachées ou fusionnées': 251
          'Prend des c. associées et/ou absorbe des c. fusionnées': 1294
          'Se crée en commune nouvelle': 106
          'Se crée en commune nouvelle avec commune déléguée propre': 716
          'Sort du périmètre du Rpicom': 2
      $evtTypes:
          quitteLeDépartementEtPrendLeCode: 903
          changeDeNomPour: 1330
          changeDeRattachementPour: 16
          changedAssociéeEnDéléguéeDe: 6
          contribueA: 21
          devientDéléguéeDe: 1700
          fusionneDans: 890 // fusion
          perdRattachementPour: 5
          resteAssociéeA: 93
          resteDéléguéeDe: 69
          reçoitUnePartieDe: 14
          sAssocieA: 1083
          seDissoutDans: 6 // c. supprimée
          seFondDans: 141 // c. absorbée par une c. nouv. sans c. dél.
    */}
    $evtLabels = [];
    $evtTypes= [];
    foreach ($rpicoms as $id => $rpicom) {
      foreach ($rpicom as $dv => $version) {
        if (($dv <> 'now') && (EvtType::type($version['évènement'])['type'] <> 'Création')) {
          if (is_string($version['évènement'])) {
            if (!isset($evtLabels[$version['évènement']]))
              $evtLabels[$version['évènement']] = 1;
            else
              $evtLabels[$version['évènement']]++;
          }
          else {
            $evtType = array_keys($version['évènement'])[0];
            if (!isset($evtTypes[$evtType]))
              $evtTypes[$evtType] = 1;
            else
              $evtTypes[$evtType]++;
          }
        }
      }
    }
    ksort($evtLabels);
    echo Yaml::dump(['$evtLabels'=> $evtLabels]);
    ksort($evtTypes);
    echo Yaml::dump(['$evtTypes'=> $evtTypes]);
  }
  die("Fin geolocHisto\n");
}

if ($_GET['action'] == 'bbtest') { // fabrication d'une base de test
  if (php_sapi_name() <> 'cli')
    echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>bbtest</title></head><body><pre>\n";
  // J'ajoute à la base de test les c. qui ont été déléguées ou associées ou qui se sont fondue ou fusionne dans
  // + fermeture transitive
  $testIds = [
    '01165'=> "Francheleins, absorbe: [01070]@1996-08, absorbe: [01003]@1983, prendPourAssociée: [01003,01070]@1974",
    '04208' => "Simiane-la-Rotonde, absorbe: [04038,04232]@2014, prendPourAssociée: [04038,04232]@1974",
    '16300' => "Val-de-Bonnieure, absorbe: [16296,16309]@2020",
    '14513' => "Pont-Farcy, avant chgt de dépt 2018-01-01",
    '50649' => "Pont-Farcy, après chgt de dépt 2018-01-01, des date-bis avant et après",
    '50592' => "Pont-Farcy deviend sa déléguée",
    '78143' => "Chateaufort, dans le 78, passe dans le 91 puis revient dans le 78",
    '91143' => "Chateaufort, dans le 78, passe dans le 91 puis revient dans le 78",
  ];
  
  $rpicomBase = new Base(__DIR__.'/rpicom', new Criteria(['not']));
  $done = false;
  while (!$done) {
    $done = true;
    foreach ($rpicomBase->contents() as $id => $rpicom) {
      if (isset($testIds[$id]))
        continue;
      foreach ($rpicom as $dv => $version) {
        //echo Yaml::dump(['$version'=> $version]);
        if (isset($testIds[$version['estAssociéeA'] ?? null])
            || isset($testIds[$version['estDéléguéeDe'] ?? null])
            || isset($testIds[$version['évènement']['seFondDans'] ?? null])
            || isset($testIds[$version['évènement']['fusionneDans'] ?? null])) {
          $testIds[$id] = 1;
          $done = false;
          break;
        }
      }
    }
  }
  ksort($testIds);
  echo Yaml::dump(['$testIds'=> $testIds], 3, 2);
  $extrait = [];
  foreach (array_keys($testIds) as $id) {
    $extrait['contents'][$id] = $rpicomBase->$id;
  }
  $rpiTest = new base($extrait, new Criteria(['not']));
  $rpiTest->ksort();
  $buildNameAdministrativeArea = <<<'EOT'
if (isset($item['now']['name']))
  return $item['now']['name']." ($skey)";
else
  return '<s>'.array_values($item)[0]['name']." ($skey)</s>";
EOT;
  $rpiTest->writeAsYaml(__DIR__.'/rpicomtest', [
    'title'=> "Base Rpicom de tests",
    'created'=> date(DATE_ATOM),
    '$schema'=> 'http://id.georef.eu/rpicom/exrpicom/$schema',
    'ydADscrBhv'=> [
      'jsonLdContext'=> 'http://schema.org',
      'firstLevelType'=> 'AdministrativeArea',
      'buildName'=> [ // définition de l'affichage réduit par type d'objet, code Php par type
        'AdministrativeArea'=> $buildNameAdministrativeArea,
      ],
      'writePserReally'=> true,
    ],
  ]);
  $rpiTest->save();
  die("Base de test sauvée dans rpicomtest.yaml/pser\n");
}

if ($_GET['action'] == 'assos2011') { // communes associées en 2011
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>assos2011</title></head><body><pre>\n";
  $state = '2011-01-01';
  $rpicoms = new Base(__DIR__.'/rpicom');
  foreach ($rpicoms->contents() as $id => $rpicom) {
    $int = interpolRpicom($rpicom, $state);
    if ($int && isset($int['version']['estAssociéeA']))
      echo Yaml::dump([$id => [$int['date'] => $int['version']]]);
  }
  die("Fin assos2011\n");
}

// détaille les évts de rattachement et d'absorption vus des c. de rattachement
if ($_GET['action'] == 'détailleEvt') {
  /*
  évènement/sAssocieA => évènementDétaillé/prendPourAssociées
  évènement/fusionneDans => évènementDétaillé/absorbe
  évènement/devientDéléguéeDe => évènementDétaillé/délègueA
  évènement/seFondDans => évènementDétaillé/absorbe
  évènement/rétablieCommeSimpleDe => ['évènementDétaillé/rétablitCommeSimple
  */
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>détailleEvt</title></head><body><pre>\n";
  $rpicomBase = new Base(__DIR__.'/rpicom', new Criteria(['not']));
  //$rpicomBase = new Base(__DIR__.'/rpicomtest', new Criteria(['not']));
  $rpicoms = $rpicomBase->contents();
  // supprime des évènementDétaillés et les réinitialise pour les c. déléguées propres
  foreach ($rpicoms as $id => $rpicom) {
    foreach ($rpicom as $dv => $version) {
      unset($rpicoms[$id][$dv]['évènementDétaillé']);
      if (($version['évènement'] ?? null) == 'Se crée en commune nouvelle avec commune déléguée propre') {
        addValToArray($id, $rpicoms[$id][$dv]['évènementDétaillé']['délègueA']);
        $rpicomBase->$id = $rpicoms[$id];
      }
    }
    $rpicomBase->$id = $rpicoms[$id];
  }
  // balaie les c. rattachées ou absorbées pour détailler l'évt de rattachement/absorption sur la c. de ratt./absorbante
  foreach ($rpicoms as $id => $rpicom) {
    foreach ($rpicom as $dv => $version) {
      if ($cratid = $version['évènement']['sAssocieA'] ?? null) {
        addValToArray($id, $rpicoms[$cratid][$dv]['évènementDétaillé']['prendPourAssociées']);
        $rpicomBase->$cratid = $rpicoms[$cratid];
      }
      if ($cratid = $version['évènement']['fusionneDans'] ?? null) {
        addValToArray($id, $rpicoms[$cratid][$dv]['évènementDétaillé']['absorbe']);
        $rpicomBase->$cratid = $rpicoms[$cratid];
      }
      if ($cratid = $version['évènement']['devientDéléguéeDe'] ?? null) {
        addValToArray($id, $rpicoms[$cratid][$dv]['évènementDétaillé']['délègueA']);
        $rpicomBase->$cratid = $rpicoms[$cratid];
      }
      if ($cratid = $version['évènement']['seFondDans'] ?? null) {
        addValToArray($id, $rpicoms[$cratid][$dv]['évènementDétaillé']['absorbe']);
        $rpicomBase->$cratid = $rpicoms[$cratid];
      }
      if ($cratid = $version['évènement']['rétablieCommeSimpleDe'] ?? null) {
        addValToArray($id, $rpicoms[$cratid][$dv]['évènementDétaillé']['rétablitCommeSimple']);
        $rpicomBase->$cratid = $rpicoms[$cratid];
      }
    }
  }
  // corrige les evts détaillés affectés par erreur à une date alors qu'ils auraient du être affecté au bis
  $évènements = [
    'Se crée en commune nouvelle avec commune déléguée propre',
    'Prend des c. associées et/ou absorbe des c. fusionnées',
    'Absorbe certaines de ses c. rattachées ou certaines de ses c. associées deviennent déléguées',
    'Se crée en commune nouvelle',
    'Commune rétablissant des c. rattachées ou fusionnées',
  ];
  foreach ($rpicoms as $id => $rpicom) {
    foreach ($rpicom as $dv => $version) {
      if (isset($rpicom["$dv-bis"])) {
        if (isset($version['évènementDétaillé']) && !in_array($version['évènement'], $évènements)) {
          $rpicom["$dv-bis"]['évènementDétaillé'] = $version['évènementDétaillé'];
          unset($rpicom[$dv]['évènementDétaillé']);
          //echo "Pour $id, transfert détails de $dv sur $dv-bis pour évènement='",json_encode($version['évènement']),"'\n";
          $rpicomBase->$id = $rpicom;
        }
      }
    }
  }
  
  if (0)
  foreach ($rpicoms as $id => $rpicom) { // affiche les évènementsDétaillés
    foreach ($rpicom as $dv => $version) {
      if (isset($rpicoms[$id][$dv]['évènementDétaillé'])) {
        echo Yaml::dump([$id => [$dv => ['évènementDétaillé'=> $rpicoms[$id][$dv]['évènementDétaillé']]]]);
      }
    }
  }
  $rpicomBase->storeMetadata(array_merge($rpicomBase->metadata(), ['évènementsDétaillésAjoutés' => date(DATE_ATOM)]));
  $rpicomBase->writeAsYaml();
  $rpicomBase->save();
  die("Fin détailleEvt dans '".$rpicomBase->metadata()['title']."'\n");
}

// analyse un évènement
class Evt {
  // l'évt défini dans cette version modifie t'il la géoloc de l'entité courante ?
  // $rattache == true <=> je traite les entités rattachées
  static function modifieGeoLoc(array $version, bool $rattache): bool {
    if (isset($version['évènementDétaillé']['absorbe'])) return true;
    if (isset($version['évènementDétaillé']['rétablitCommeSimple'])) return true;
    if (!$rattache) {
      // uniquement les c. simples
      //if (isset($version['évènement']['sAssocieA'])) return true;
      if (isset($version['évènementDétaillé']['prendPourAssociées'])) return true;
      //if (isset($version['évènement']['devientDéléguéeDe'])) return true;
      if (isset($version['évènementDétaillé']['délègueA'])) return true;
    }
    return false;
  }
};

// caractéristique sur les versions
class Version {
  // teste si une version correspond à une entité rattachée
  static function estRattachee(array $version): bool {
    return (isset($version['estAssociéeA']) || isset($version['estDéléguéeDe'])
         || isset($version['estArrondissementMunicipalDe']));
  }
  
  // définit un type d'entité
  static function type(array $version): string {
    return isset($version['estAssociéeA']) ? 'COMA' :
        (isset($version['estDéléguéeDe']) ? 'COMD' :
          (isset($version['estArrondissementMunicipalDe']) ? 'ARM' : 'COMS'));
  }
  
  // la c de rattachement ou '
  static function parent(array $version): string {
    return $version['estAssociéeA'] ?? ($version['estDéléguéeDe'] ?? ($version['estArrondissementMunicipalDe'] ?? ''));
  }
  
  // retourne '' si aucun évt n'est associé à la version, soit la clé de l'evt soit le libellé
  static function evt(array $version): string {
    if (!($evt = ($version['évènement'] ?? null)))
      return '';
    elseif (is_array($evt))
      return array_keys($evt)[0];
    else
      return $evt;
  }

  // encodage court de l'évènement
  static function shortEvt(array $version): string {
    $simples = [
      "Se crée en commune nouvelle avec commune déléguée propre" => 'seCréeEnComNouvAvecDélPropre',
      "Se crée en commune nouvelle" => 'seCréeEnComNouvelle',
      "Prend des c. associées et/ou absorbe des c. fusionnées" => 'PrendAssocOuAbsorbe',
    ];
    if (!($evt = ($version['évènement'] ?? null)))
      return '';
    elseif (is_array($evt)) {
      $key = array_keys($evt)[0];
      if ($key == 'changeDeNomPour')
        return 'changeDeNom';
      else
        return str_replace(['"',':'], ['', ': '], json_encode($evt, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    }
    else
      return $simples[$evt] ?? $evt;
  }
};

// caractéristique d'un géolocalisant
class GeoLoc {
  protected $label; // string - l'identifiant du GéoLoc
  protected $state; // string - la date de validité du GeoLoc
  protected $rattache; // bool - indique si le géolocalisant connait les entités rattachées

  function __construct(string $label, string $state, bool $rattache) {
    $this->label = $label;
    $this->state = $state;
    $this->rattache = $rattache;
  }
  
  function __toString(): string { return $this->label.($this->rattache ? 'R':'S'); }
  
  function state() { return $this->state; }
  
  // affectation du label à la version $dvref de $id et aux versions antérieures à $dvref, y c. avec des id différents
  // Traite aussi le changement d'id et le transfert vers l'ancien id
  function affectEtPropAuxVAnterieures(Base $rpicoms, string $id, string $dvref, bool $suivi=false): void {
    //echo "$this ->affectEtPropAuxVAnterieures(rpicoms, id=$id, dvref=$dvref, suivi=",$suivi?'true':'false',")\n";
    $rpicom = $rpicoms->$id;
    if (!$suivi) { // première entrée
      $anterieure = false;
    }
    else { // il s'agit d'un transfert
      //echo Yaml::dump([$id => $rpicom], 3, 2);
      $versionLaPlusRecente = Rpicom::versionLaPlusRecente($rpicom);
      //echo Yaml::dump(['$versionLaPlusRecente' => $versionLaPlusRecente], 3, 2);
      
      $dateLaPlusRecente = Rpicom::dateLaPlusRecente($rpicom);
      if ((Version::evt($versionLaPlusRecente) == 'quitteLeDépartementEtPrendLeCode')
          && (($dateLaPlusRecente == $dvref) || ($dateLaPlusRecente == "$dvref-bis") || ("$dateLaPlusRecente-bis" == $dvref))) {
        // Transfert simple, l'évt le plus récent correspond ainsi que les dates
        $anterieure = true;
      }
      else {
        // Pb de transfert, je recherche la date correcte de transfert dans $rpicom aux bis prêts
        foreach (array_keys($rpicom) as $dv) {
          if (($dv == $dvref) || ($dv == "$dvref-bis") || ("$dv-bis" == $dvref)) {
            $anterieure = false;
            $dvref = $dv;
            break;
          }
        }
        if (!isset($anterieure)) {
          echo "Pb de transfert ligne ",__LINE__,"\n";
          die();
        }
      }
    }
    foreach ($rpicom as $dv => $version) {
      if ($dv == $dvref) {
        if (!$this->rattache && Version::estRattachee($version))
          return;
        addValToArray($this->label, $rpicom[$dv]['geoloc']);
        $anterieure = true;
      }
      elseif ($anterieure) {
        if (Evt::modifieGeoLoc($version, $this->rattache))
          break;
        if (isset($version['évènement']['arriveDansLeDépartementAvecLeCode'])) {
          // attention, l'appel récursif multiple ne doit pas conduire à effacer la dernière version du rpicom
          $rpicoms->$id = $rpicom;
          $this->affectEtPropAuxVAnterieures($rpicoms, $version['évènement']['arriveDansLeDépartementAvecLeCode'], $dv, true);
          return;
        }
        //echo Yaml::dump([$id=> [$dv=> $version]]),"\n";
        if (EvtType::type($version['évènement'])['type'] == 'Création')
          break;
        addValToArray($this->label, $rpicom[$dv]['geoloc']);
      }
    }
    $rpicoms->$id = $rpicom;
  }

  // propagation aux versions postérieures à $dvref, y c avec id différents
  function propAuxVPosterieures(Base $rpicoms, string $id, string $dvref, bool $suivi=false): void {
    //echo "$this ->propAuxVPosterieures(rpicoms, id=$id, dvref=$dvref)\n";
    $rpicom = $rpicoms->$id;
    if (!$suivi) {
      $posterieure = false;
    }
    else { // A développer quand nécessaire
      die("transfert ligne ".__LINE__);
    }
    foreach (array_reverse($rpicom) as $dv => $version) {
      if ($dv == $dvref) {
        //echo "  version=",json_encode($version),"\n";
        if (!$this->rattache && Version::estRattachee($version))
          return;
        $posterieure = true;
        if (Evt::modifieGeoLoc($version, $this->rattache))
          return;
      }
      elseif ($posterieure) {
        addValToArray($this->label, $rpicom[$dv]['geoloc']);
        if (Evt::modifieGeoLoc($version, $this->rattache))
          break;
        if (isset($version['évènement']['quitteLeDépartementEtPrendLeCode']))
          $this->propAuxVPosterieures($rpicoms, $version['évènement']['quitteLeDépartementEtPrendLeCode'], $dv, true);
      }
    }
    $rpicoms->$id = $rpicom;
  }
};

if ($_GET['action'] == 'setGeoloc') {
  /*
  0 -> 2880 entités au moins partiellement non géolocalisées sur 39162, Date la plus récente 2020-01-01
  GeoFla2011 -> 2745 entités au moins partiellement non géolocalisées sur 39162
  AeCog2019+GeoFla2011 -> 2738 entités au moins partiellement non géolocalisées sur 39162, Date la plus récente 2020-01-01
  AeCog2019+GeoFla2011-16 -> 2734 entités au moins partiellement non géolocalisées sur 39162, Date la plus récente 2020-01-01
  */
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>setGeoloc</title></head><body><pre>\n";
  $rpicomBase = new Base(__DIR__.'/rpicom', new Criteria(['not']));
  //$rpicomBase = new Base(__DIR__.'/rpicomtest', new Criteria(['not']));
  if (!isset($rpicomBase->metadata()['évènementsDétaillésAjoutés']))
    die("Erreur, les évènements détaillés doivent être d'abord ajoutés\n");
  // réinit ie suppression des geoloc
  foreach ($rpicomBase->contents() as $id => $rpicom) {
    foreach ($rpicom as $dv => $version) {
      unset($rpicom[$dv]['geoloc']);
      unset($rpicom[$dv]['commeDéléguée']['geoloc']);
      unset($rpicom[$dv]['sansSesAssociées']['geoloc']);
    }
    $rpicomBase->$id = $rpicom;
  }
  // Affectation initiale des geoloc AeCog2020
  $AeCog2020 = new GeoLoc('AeCog2020', 'now', true);
  foreach ($rpicomBase->contents() as $id => $rpicom) {
    if (isset($rpicom['now'])) {
      $AeCog2020->affectEtPropAuxVAnterieures($rpicomBase, $id, 'now');
    }
  }
  //echo "<b>RpicomBase</b>=\n",Yaml::dump($rpicomBase->contents(), 3, 2); die("Arrêt ligne ".__LINE__);
  
  // intégration de GoLoc intermédiaires - Attention GéoFla ne comporte que les communes simples
  foreach ([
        new GeoLoc('AeCog2019', '2019-01-01', false), // AeCog2019 n'a pas d'entités rattachées
        new GeoLoc('AeCog2018', '2018-01-01', false), // AeCog2019 n'a pas d'entités rattachées
        new GeoLoc('AeCog2017', '2017-01-01', false), // AeCog2019 n'a pas d'entités rattachées
        new GeoLoc('GeoFla2016', '2016-01-01', false),
        new GeoLoc('GeoFla2015', '2015-01-01', false),
        new GeoLoc('GeoFla2014', '2014-01-01', false),
        new GeoLoc('GeoFla2013', '2013-01-01', false),
        new GeoLoc('GeoFla2012', '2012-01-01', false),
        new GeoLoc('GeoFla2011', '2011-01-01', false),
        new GeoLoc('GeoFla2010', '2010-01-01', false),
      ] as $geoloc) {
    $state = $geoloc->state();
    if (0) { // lecture de GéoFLA dans $geofla
      $geofla = []; // [id => properties]
      $geoflaGeoJFile = new GeoJFile($_GET['file'], 'Windows-1252');
      foreach ($geoflaGeoJFile->quickReadFeatures() as $feature) {
        $prop = $feature['properties'];
        $geofla[$prop['INSEE_COM']] = $prop;
      }
    }
    foreach ($rpicomBase->contents() as $id => $rpicom) {
      if ($int = interpolRpicom($rpicom, $state)) {
        //echo "interpolRpicom: $id -> $int[date]\n";
        $dint = $int['date'];
        $geoloc->affectEtPropAuxVAnterieures($rpicomBase, $id, $dint);
        $geoloc->propAuxVPosterieures($rpicomBase, $id, $dint);
      }
    }
  }
  //echo "<b>RpicomBase</b>=\n",Yaml::dump($rpicomBase->contents(), 3, 2); die("Arrêt ligne ".__LINE__);

  $rpicomBase->writeAsYaml();
  $rpicomBase->save();
  
  // setGeolocInfo
  if (0) { // nbre  de versions non géolocalisées et date la plus récente
    $nogeoloc = 0;
    $dlaplusrécente = '0000';
    foreach ($rpicomBase->contents() as $id => $rpicom) {
      foreach ($rpicom as $dv => $version) {
        if (!isset($version['geoloc'])) {
          $nogeoloc++;
          if (strcmp($dv, $dlaplusrécente) > 0)
            $dlaplusrécente = $dv;
          break;
        }
      }
    }
    echo $nogeoloc," entités au moins partiellement non géolocalisées sur ",count($rpicomBase->contents()),"\n";
    echo "Date la plus récente $dlaplusrécente\n";
  }
  
  if (1) { // versions de c. simples non géolocalisées et date la plus récente 
    /*
    1730 rpicom ayant une version de commune simple non géolocalisée
    Date la plus récente 2011-01-01
    Cela signifie que tte version de c.simple après le 1/1/2011 peut être géolocalisée
    */
    $nogeoloc = 0;
    $dlaplusrécente = '0000';
    foreach ($rpicomBase->contents() as $id => $rpicom) {
      foreach ($rpicom as $dv => $version) {
        if (!isset($version['geoloc']) && (EvtType::type($version['évènement'])['type'] <> 'Création')
            && !isset($version['estAssociéeA']) && !isset($version['estDéléguéeDe'])) {
          echo "dv=$dv, rpicom=",Yaml::dump([$id => $rpicom], 1, 2);
          if (strcmp($dv, $dlaplusrécente) > 0)
            $dlaplusrécente = $dv;
          $nogeoloc++;
          break;
        }
      }
    }
    echo $nogeoloc," rpicom ayant une version de commune simple non géolocalisée\n";
    echo "Date la plus récente $dlaplusrécente\n";
  }

  if (0) // affiche les entités au moins partiellement non géolocalisées
  foreach ($rpicomBase->contents() as $id => $rpicom) {
    foreach ($rpicom as $dv => $version) {
      if (!isset($version['geoloc']) && (EvtType::type($version['évènement'])['type'] <> 'Création')) {
        echo "dv=$dv, rpicom=",Yaml::dump([$id => $rpicom], 1, 2);
        break;
      }
    }
  }
  die("Fin setGeoloc\n");
}

require_once __DIR__.'/rpimap/igeojfile.inc.php';

// fichier GeoJSON en écriture
class GeoJFileW {
  protected $file = null;
  protected $first;
  
  // création
  function __construct(string $filename) {
    $this->file = fopen($filename, 'w');
    $start = <<<'EOT'
{
"type": "FeatureCollection",
"name": "Rpicom",
"crs": { "type": "name", "properties": { "name": "urn:ogc:def:crs:OGC:1.3:CRS84" } },
"features": [
EOT;
    fwrite($this->file, "$start\n");
    $this->first = true;
  }
  
  // fermeture
  function close() {
    fwrite($this->file, "\n]\n}\n");
    fclose($this->file);
    $this->file = null;
  }
  
  // écriture d'un feature
  function write(array $geojson): void {
    if (!$this->first) fwrite($this->file, ",\n");
    $this->first = false;
    fwrite($this->file, json_encode($geojson, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  }
  
  // fabrication et enregistrement d'un feature à partir d'un enregistrement $record
  function geoloc(IndGeoJFile $igeojfile, array $record): bool {
    if ($record['dataset'] == 'A VOIR') { // géométrie en mer pour retrouver facilement ces objets
      $geometry = ['type'=> 'Polygon', 'coordinates'=> [[[-4,46],[-4,47],[-3,47],[-3,46],[-4,46]]]];
    }
    else {
      $comid = $record['overEstim'] ? $record['overEstim'] : $record['comid'];
      if (!($georef = $igeojfile->feature($comid, $record['dataset'])))
        return false;
      $geometry = $georef['geometry'];
    }
    $this->write(['type'=> 'Feature', 'properties'=> $record, 'geometry'=> $geometry]);
    return true;
  }
};

// retourne le dataset dans lequel je trouve un majorant de id ainsi que l'id majorant défini dans ce dataset
// $overEstimId en entrée est celui de la c. absorbante ou d'un majorant
// retourne [datasetId, overEstimId] avec overEstimId défini dans datasetId
function overEstim(array $rpicoms, string $overEstimId, string $dv): array {
  //echo yaml::dump(['majorant'=> ['$id'=> $id]]);
  //echo yaml::dump(['$id'=> $rpicoms[$id]]);
  $overEstim = $rpicoms[$overEstimId];
  if (isset($overEstim['now'])) { // si le majorant existe actuellement j'utilise cette version
    return Version::estRattachee($overEstim['now']) ? ['Ae2020CogR', $overEstimId] : ['Ae2020Cog', $overEstimId];
  }
  $vLaPlusRecente = Rpicom::versionLaPlusRecente($overEstim);
  if (isset($vLaPlusRecente['évènement']['fusionneDans'])) { // si la c. absorbante a elle même été fusionnée alors récursion
    return overEstim($rpicoms, $vLaPlusRecente['évènement']['fusionneDans'], $dv);
  }
  elseif (isset($vLaPlusRecente['évènement']['seFondDans'])) { // si la c. absorbante a elle même été fusionnée alors récursion
    return overEstim($rpicoms, $vLaPlusRecente['évènement']['seFondDans'], $dv);
  }
  elseif (isset($vLaPlusRecente['évènement']['quitteLeDépartementEtPrendLeCode'])) { // si la c. abs. a changé de code alors récursion
    return overEstim($rpicoms, $vLaPlusRecente['évènement']['quitteLeDépartementEtPrendLeCode'], $dv);
  }
  echo Yaml::dump([
    'overEstim'=>[
      '$overEstimId'=> $overEstimId,
      '$overEstim'=> $overEstim,
      '$dv'=> $dv,
      '$vLaPlusRecente'=> $vLaPlusRecente
    ]], 3);
  throw new Exception("Cas non traité");
}

// retourne la provenance et le type de la géométrie sous la forme ['S'+'R'=> [datasetId, overEstim]]
//  / datasetId est un identifiant de dataset défini dans IndGeoJFile
//  / overEstim vaut '' si la géoLoc est disponible, sinon l'id d'un majorant
// $previous est le retour effectué pour une version ultérieure du même id
function defDataset(array $rpicoms, string $id, string $dvref, array $datasets, array $previous): array {
  $rpicom = $rpicoms[$id];
  $version = $rpicom[$dvref];
  $endevt = Version::shortEvt($version);
  if ($dvref == 'now') { // si la version existe alors la géométrie est définie dans Ae2020Cog ou Ae2020CogR
    // je traite de plus le bug IGN des entités absentes par du Cog en utilisant alors la v. Ae2019
    return [
      'S'=> in_array('Ae2020Cog', $datasets) ? ['Ae2020Cog', '']
          : (in_array('Ae2019Cog', $datasets) ? ['Ae2019Cog', ''] : ['Erreur', '']),
      'R'=> in_array('Ae2020CogR', $datasets) ? ['Ae2020CogR', ''] : ['Erreur', ''],
    ];
  }
  elseif ($endevt == 'changeDeNom') { // pas de chgt de géométrie
    return $previous;
  }
  if (Version::evt($version) == 'fusionneDans') { // cas d'une entité qui fusionne
    if (!isset($version['estAssociéeA']) && !isset($version['estDéléguéeDe'])) { // cas d'une COM qui fusionne
      if (strcmp($dvref, '2003-01-01') < 0) { // date de fusion avant 2003 => pas de référentiel => majorant
        return ['S'=> overEstim($rpicoms, $version['évènement']['fusionneDans'], $dvref)];
      }
      else { // COM qui fusionne après 2003 => géométrie présente dans un référentiel, je choisis le plus récent
        /*
          $id: '21551'
          $dvref: '2009-01-01'
          $rpicom:
            '2009-01-01':
              évènement: { fusionneDans: 21084 }
              name: Saint-Germain-Source-Seine
        */
        return ['S'=> [$datasets[0], '']];
      }
    }
    elseif (strcmp($dvref, '2003-01-01') < 0) { // cas d'une COMA/COMD qui fusionne avant 2003 => pas de réf. => majorant
      /*
        $dvref: '1983-01-01'
        $rpicom:
            '1983-01-01':
                évènement: { fusionneDans: '01165' }
                name: Amareins
                estAssociéeA: '01165'
            '1974-01-01':
                évènement: { sAssocieA: '01165' }
                name: Amareins
      */
      return ['R'=> overEstim($rpicoms, $version['évènement']['fusionneDans'], $dvref)];
    }
    else { // date de fusion après 2003
      // recherche une éventuelle version précédente correspondant à l'association ou devenueDéléguée
      $dvprec = null;
      foreach ($rpicom as $dv => $v) { // recherche de la version précédente à $dvref
        if ((strcmp($dv, $dvref) < 0) && (Version::evt($v) <> 'resteAssociéeA')) {
          // première version antérieure à $dvref <> resteAssociéeA
          $dvprec = $dv;
          break;
        }
      }
      if (!$dvprec) // si pas de version précédente, ex association avant 1943
        return ['R'=> overEstim($rpicoms, $version['évènement']['fusionneDans'], $dvref)]; // => majorant
      elseif (isset($rpicom[$dvprec]['évènement']['sAssocieA']) || isset($rpicom[$dvprec]['évènement']['devientDéléguéeDe'])) {
        // si la version précédente est une sAssocieA ou une devientDéléguéeDe
        if (strcmp($dvprec, '2003-01-01') < 0) { // et que cette association/déléguée est d'avant 2003 => majorant
          /*
            $dvref: '2015-01-01'
            $rpicom:
                '2015-01-01':
                    évènement: { fusionneDans: '01283' }
                    name: Veyziat
                    estAssociéeA: '01283'
                '1973-01-01':
                    évènement: { sAssocieA: '01283' }
                    name: Veyziat
          */
          return ['R'=> overEstim($rpicoms, $version['évènement']['fusionneDans'], $dvref)];
        }
        else { // association/déléguée d'après 2003 => sa géométrie est définie dans un des référentiels disponibles
          /*
            $id: '16296'
            $dvref: '2020-01-01'
            $rpicom:
                '2020-01-01':
                    évènement: { fusionneDans: 16300 }
                    name: Saint-Amant-de-Bonnieure
                    estDéléguéeDe: 16300
                '2018-01-01':
                    après: { name: Saint-Amant-de-Bonnieure, estDéléguéeDe: 16300 }
                    évènement: { devientDéléguéeDe: 16300 }
                    name: Saint-Amant-de-Bonnieure
          */
          return ['R'=> [$datasets[0], 'DEF']];
        }
      }
    }
    echo Yaml::dump(['defDataset'=> ['$id'=> $id, '$dvref'=> $dvref, '$rpicom'=> $rpicom]], 4);
  }
  else {
    return ['S'=> ['A VOIR',''], 'R'=> ['A VOIR','']];
  }
}

if ($_GET['action'] == 'geoloc') { // génération d'un fichier géolocalisé de chaque version
  {/* structuration:
    vid: identifiant unique de la version de la forme comid@start avec la lettre en plus afin de distinguer les déléguées propres
    comid: code INSEE
    type: COMS, COMA, COMD ou ARM
    parent: si non COMS alors id de la COMS de rattachement
    start: date de début de la version, 1943-01-01 par défaut
    end: lendemain de la date de fin de la version, 9999-12-31 pour les entités actuelles
    startEvt: codification de l'évènement de début de la version, '' pour les e. existantes au 1/1/1943
    endEvt: codification de l'évènement de fin de la version, '' pour les e. actuelles
    name: nom en minuscules accentuées
    overEstim: si la géom est celle d'un majorant alors id du majorant sinon ''
    dataset: source de la géométrie
    geom: géolocalisation, dans un premier temps non rempli
  */}
  set_time_limit(5*60);
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>geoloc</title></head><body><pre>\n";
  $rpicomBase = new Base(__DIR__.'/rpicom', new Criteria(['not']));
  //$rpicomBase = new Base(__DIR__.'/rpicomtest', new Criteria(['not']));
  $rpicoms = $rpicomBase->contents();
  $igeojfile = new IndGeoJFile(__DIR__.'/data/aegeofla/index.igf');
  $geojfilew = new GeoJFileW(__DIR__.'/rpicom.geojson');
  $nbrecords = 0;
  $nbAVoirs = 0;
  $nbErreurs = 0;
  foreach ($rpicoms as $id => $rpicom) {
    $dvs = array_keys($rpicom);
    $datasets = $igeojfile->datasets($id);
    $dataset = []; // ['S'+'R'=> [datasetId, overEstim]]
    foreach ($dvs as $nodv => $dv) {
      $version = $rpicom[$dv];
      if (!isset($version['name'])) continue; // si pas de nom alors ce n'est pas une version mais uniq. un évt.
      $start = $dvs[$nodv+1] ?? '1943-01-01';
      $idv = "$id@$start";
      $endEvt = Version::shortEvt($version);
      $dataset = defDataset($rpicoms, $id, $dv, $datasets, $dataset);
      $record = [
        'vid'=> $idv,
        'comid'=> $id,
        'type'=> Version::type($version),
        'parent'=> Version::parent($version),
        'start'=> $start,
        'startEvt'=> isset($dvs[$nodv+1]) ? Version::shortEvt($rpicom[$dvs[$nodv+1]]) : '',
        'end'=> ($dv == 'now') ? '9999-12-31' : $dv,
        'endEvt'=> $endEvt,
        'name'=> $version['name'],
        'overEstim'=> Version::type($version) == 'COMS' ? $dataset['S'][1] : $dataset['R'][1],
        'dataset'=> Version::type($version) == 'COMS' ? $dataset['S'][0] : $dataset['R'][0],
      ];
      echo Yaml::dump([$idv => $record]);
      if (!$geojfilew->geoloc($igeojfile, $record))
        $nbErreurs++;
      if ($record['dataset'] == 'A VOIR')
        $nbAVoirs++;
      $nbrecords++;
      if (isset($version['commeDéléguée'])) {
        $idv = "${id}D@$start";
        $record = [
          'vid'=> $idv,
          'comid'=> $id,
          'type'=> 'COMD',
          'parent'=> $id,
          'start'=> $start,
          'startEvt'=> isset($dvs[$nodv+1]) ? Version::shortEvt($rpicom[$dvs[$nodv+1]]) : '',
          'end'=> ($dv == 'now') ? '9999-12-31' : $dv,
          'endEvt'=> Version::shortEvt($version),
          'name'=> $version['commeDéléguée']['name'],
          'overEstim'=> $dataset['R'][1],
          'dataset'=> $dataset['R'][0],
        ];
        echo Yaml::dump([$idv => $record]);
        if (!$geojfilew->geoloc($igeojfile, $record))
          $nbErreurs++;
        if ($record['dataset'] == 'A VOIR')
          $nbAVoirs++;
        $nbrecords++;
      }
      //if ($nbrecords >= 1000) break 2;
    }
  }
  $geojfilew->close();
  die("Fin geoloc, $nbrecords objets traités dont $nbErreurs erreurs et $nbAVoirs à voir\n");
}

die("Aucune commande $_GET[action]\n");
