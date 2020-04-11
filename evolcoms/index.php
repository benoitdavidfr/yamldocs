<?php
/*PhpDoc:
name: index.php
title: index.php - diverses actions evolcoms
doc: |
  Définition de différentes actions accessibles par le Menu
journal: |
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
screens:
classes:
functions:
*/

ini_set('memory_limit', '2048M');

require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/base.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;


if (($_GET['action'] ?? null) == 'delBase') { // suppression de la base
  if (is_file(__DIR__.'/base.pser'))
    unlink(__DIR__.'/base.pser');
  unset($_GET['action']);
}

{/*PhpDoc: screens
name: Menu
title: Menu - permet d'exécuter les différentes actions définies
*/}
if (!isset($_GET['action'])) { // Menu
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>menu</title></head><body>\n";
  echo "<a href='?action=csvMvt2yaml&amp;file=mvtcommune2020.csv'>Affiche mvtcommune2020.csv</a><br>\n";
  echo "<a href='?action=csvCom2html&amp;file=communes2020.csv&amp;format=csv'>",
    "Affiche communes2020.csv</a><br>\n";
  echo "<a href='?action=csvCom2html&amp;file=communes-01012019.csv&amp;format=csv'>",
    "Affiche les communes au 1/1/2019</a><br>\n";
  echo "<a href='?action=csvCom2html&amp;file=France2018.txt&amp;format=txt'>",
    "Affiche France2018.txt</a><br>\n";
  echo "<a href='?action=csvCom2html&amp;file=France2010.txt&amp;format=txt'>",
    "Affiche France2010.txt</a><br>\n";
  echo "<a href='?action=csvCom2html&amp;file=France2000.txt&amp;format=txt'>",
    "Affiche France2000.txt</a><br>\n";
  echo "<a href='?action=delBase'>effacement de la base</a><br>\n";
  echo "<a href='?action=buildState&amp;state=2020-01-01&amp;file=communes2020.csv&amp;format=csv'>",
    "intégration dans la base de l'état au 1/1/2020</a><br>\n";
  echo "<a href='?action=buildState&amp;state=2019-01-01&amp;file=communes-01012019.csv&amp;format=csv'>",
    "intégration dans la base de l'état au 1/1/2019</a><br>\n";
  echo "<a href='?action=buildState&amp;state=2018-01-01&amp;file=France2018.txt&amp;format=txt'>",
    "intégration dans la base de l'état au 1/1/2018</a><br>\n";
  echo "<a href='?action=buildState&amp;state=2010-01-01&amp;file=France2010.txt&amp;format=txt'>",
    "intégration dans la base de l'état au 1/1/2010</a><br>\n";
  echo "<a href='?action=integrateState&amp;state=2000-01-01&amp;file=France2000.txt&amp;format=txt'>",
    "intégration dans la base de l'état au 1/1/2000</a><br>\n";
  echo "<a href='?action=buildUpdates&amp;file=mvtcommune2020.csv'>","affichage de la base avec les maj</a><br>\n";
  echo "<br>\n";
  echo "<a href='?action=genCom1943'>génération du fichier des communes au 1/1/1943</a><br>\n";
  echo "<a href='?action=genEvols'>génération du fichier des évolutions</a><br>\n";
  echo "<a href='?action=comp&amp;date=2000-01-01'>comparaison entre l'état généré au 1/1/2000 et celui de l'INSEE</a><br>\n";
  echo "<a href='?action=buildState&amp;state=2000-01-01&amp;file=France2000.txt&amp;format=txt'>",
    "création du fichier Yaml des communes au 1/1/2000 à partir du fichier INSEE</a><br>\n";
    echo "<a href='?action=compare'>Comapre 2 fichiers entre eux</a>\n";
  die();
}

// Classe des mouvements, agrége plusieurs lignes en un mouvement
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
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>mvts</title></head><body>\n";
  $headersToDelete = ['tncc_av','ncc_av','nccenr_av','tncc_ap', 'ncc_ap', 'nccenr_ap'];
  echo "<pre>";
  echo "title: lecture du fichier $_GET[file]\n";
  $file = fopen($_GET['file'], 'r');
  $headers = fgetcsv($file);
  $mvt = null;
  $nbrec = 0;
  while($record = fgetcsv($file)) {
    //print_r($record);
    $rec = [];
    foreach ($headers as $i => $header) {
      if (!in_array($header, $headersToDelete))
        $rec[$header] = $record[$i];
    }
    //print_r($rec);
    //echo str_replace("-\n ", '-', Yaml::dump([0 => $rec], 99, 2));
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
    echo str_replace("-\n ", '-', Yaml::dump([0 => $yaml], 2, 2));
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

if ($_GET['action'] == 'extrait') { // extrait
  $file = fopen($_GET['file'], 'r');
  $sep = $_GET['format'] == 'csv' ? ',' : "\t";
  $headers = fgetcsv($file, 0, $sep);
  // un des fichiers comporte des caractères parasites au début ce qui perturbe la détection des headers
  foreach ($headers as $i => $header)
    if (preg_match('!"([^"]+)"!', $header, $matches))
      $headers[$i] = $matches[1];
  echo "<pre>headers="; print_r($headers); echo "</pre>\n";
  //echo "<table border=1><th>",implode('</th><th>', $headers),"</th>\n";
  //echo "</table>\n";
  while($record = fgetcsv($file, 0, $sep)) {
    $rec = [];
    foreach ($headers as $i => $header) {
      $rec[strtolower($header)] = $_GET['format'] == 'csv' ? $record[$i] : utf8_encode($record[$i]);
    }
    $cinsee = $_GET['format'] == 'csv' ? $rec['com'] : "$rec[dep]$rec[com]";
    if ($cinsee == $_GET['insee']) {
      echo "<pre>rec="; print_r($rec); echo "</pre>\n";
      echo "<table border=1><th>",implode('</th><th>', $headers),"</th>\n";
      echo "<tr><td>",implode('</td><td>', $record);
      echo "</table>\n";
      die();
    }
  }
  die("Aucun enregistrement trouvé");
}

// convertit un enregistrement txt en csv, cad de l'ancien frmat INSEE dans le nouveau
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
  Le paramètre $array n'existe pas forcément. Par exemple si $a = [] on peut utiliser $a['key'] comme paramètre.
*/}
function addValToArray($val, &$array): void {
  if (!isset($array))
    $array = [ $val ];
  else
    $array[] = $val;
}

if ($_GET['action'] == 'buildUpdates') { // intègre les Mvts dans la base et affiche le résultat, sans sauver la base
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
      $yaml['13055']['ardtMun'][$cinsee] = $ardtM;
      $yaml[$cinsee]['ardtMunDe'] = '13055';
    }
    elseif (substr($cinsee, 0, 2)=='69') {
      $yaml['69123']['ardtMun'][$cinsee] = $ardtM;
      $yaml[$cinsee]['ardtMunDe'] = '69123';
    }
    elseif (substr($cinsee, 0, 2)=='75') {
      $yaml['75056']['ardtMun'][$cinsee] = $ardtM;
      $yaml[$cinsee]['ardtMunDe'] = '75056';
    }
  }
  ksort($yaml);
  echo Yaml::dump([
      'title'=> "Fichier des communes de 1943",
      'source'=> "Fabriqué à partir de GéoHisto en utilisant http://localhost/yamldoc/pub/evolcoms/?action=genCom1943",
      'created'=> date(DATE_ATOM),
      'contents'=> $yaml,
    ], 99, 2);
  die();
}

require_once __DIR__.'/grpmvts.inc.php';

{/*PhpDoc: screens
name: genEvols
title: genEvols - génération du fichier des évolutions et enregistrement d'un fichier d'état
*/}
if ($_GET['action'] == 'genEvols') { // génération du fichier des évolutions et enregistrement d'un fichier d'état
  $datefin = '2000-01-01';
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>genEvols</title></head><body><pre>\n";
  $trace = new Criteria([]); // aucun critère, tout est affiché
  //$trace = new Criteria(['mod'=> ['not'=> ['10','20','21','30','31','33','34','41','50']]]);
  //$trace = new Criteria(['mod'=> ['21']]); 
  if (1) { // lecture de mvtcommune2020.csv dans $mvtcoms et tri par ordre chronologique
    $mvtcoms = []; // Liste des mvts retriée par ordre chronologique
    $file = fopen(__DIR__.'/mvtcommune2020.csv', 'r');
    $headers = fgetcsv($file);
    $nbrec = 0;
    while($record = fgetcsv($file)) { // lecture des mvts et structuration dans $mvtcoms par date d'effet
      //print_r($record);
      $rec = [];
      foreach ($headers as $i => $header)
        $rec[$header] = $record[$i];
      //print_r($rec);
      $yaml = [
        'mod'=> $rec['mod'],
        'label'=> GroupMvts::ModLabels[$rec['mod']],
        'date_eff'=> $rec['date_eff'],
        'avant'=> [
          'type'=> $rec['typecom_av'],
          'id'=> $rec['com_av'],
          'name'=> $rec['libelle_av'],
        ],
        'après'=> [
          'type'=> $rec['typecom_ap'],
          'id'=> $rec['com_ap'],
          'name'=> $rec['libelle_ap'],
        ],
      ];
      addValToArray($yaml, $mvtcoms[$rec['date_eff']][$rec['mod']]);
      //echo str_replace("-\n ", '-', Yaml::dump([0 => $rec], 99, 2));
      //if (++$nbrec >= 100) break; //die("nbrec >= 100");
    }
    fclose($file);
    ksort($mvtcoms); // tri sur la date d'effet
    //echo Yaml::dump($mvtcoms, 99, 2);
  }
  $coms = new Base(__DIR__.'/com1943', $trace); // Lecture de com1943.yaml dans $coms
  //$mvtcoms = ['1976-01-01' => $mvtcoms['1976-01-01']]; // Test de mod=21
  //$mvtcoms = ['1990-02-01' => $mvtcoms['1990-02-01']]; // Test de aggrMvtsCom()
  //$mvtcoms = ['2020-01-01' => $mvtcoms['2020-01-01']]; // Test de mod=70
  foreach($mvtcoms as $date_eff => $mvtcomsD) {
    if (isset($datefin) && (strcmp($date_eff, $datefin) > 0)) {
      $coms->writeAsYaml(
          __DIR__."/com${datefin}gen",
          [
            'title'=> "Fichier des communes reconstitué au $datefin",
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
        if ($trace->is(['mod'=> $mod]))
          echo '<b>',Yaml::dump(['$evol'=> $evol], 3, 2),"</b>\n";
      }
    }
  }
  die();
}

if ($_GET['action'] == 'buildState') { // fabrication d'un fichier Yaml d'un état des communes
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>buildState $_GET[file]</title></head><body>\n";
  echo "<h3>lecture du fichier $_GET[file]</h3><pre>\n";
  //die("Fin ligne ".__LINE__);
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
    //echo "<pre>record="; print_r($record); echo "</pre>\n";
    $rec = [];
    foreach ($headers as $i => $header) {
      $rec[strtolower($header)] = $_GET['format'] == 'csv' ?
          $record[$i] :
            mb_convert_encoding ($record[$i], 'UTF-8', 'Windows-1252');
    }
    //echo "<pre>rec="; print_r($rec); echo "</pre>\n";
    if ($_GET['format'] == 'txt') {
      $rec = conv2Csv($rec);
      if ($rec['typecom'] == 'X')
        continue;
    }
    //echo "$rec[nccenr] ($typecom $rec[com])<br>\n";
    if (!$rec['comparent']) {
      $coms[$rec['com']] = ['name'=> $rec['nccenr']];
    }
    else {
      $enfants[$rec['com']] = $rec;
    }
    //if ($nbrec >= 10) die("<b>die nbrec >= 10</b>");
  }
  foreach ($enfants as $c => $enfant) {
    $comparent = $enfant['comparent'];
    if ($enfant['typecom'] == 'COMA')
      $childrenTag = ['associées','associéeA']; 
    elseif ($enfant['typecom'] == 'COMD')
      $childrenTag = ['déléguées', 'déléguéeDe']; 
    elseif ($enfant['typecom'] == 'ARM')
      $childrenTag = ['ardtMun', 'ardtMunDe']; 
    $coms[$comparent][$childrenTag[0]][$c] = ['name'=> $enfant['nccenr']];
    if ($c <> $comparent)
      $coms[$c] = [$childrenTag[1] => $comparent];
  }
  ksort($coms);
  // post-traitement - suppression des communes simples ayant uneiquement un nom
  foreach ($coms as $c => $com) {
    if (isset($com['name']) && (count(array_keys($com))==1))
      unset($coms[$c]);
  }
  echo str_replace("-\n  ", "- ", Yaml::dump($coms, 99, 2));
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

// renvoie l'union des clés de $a et $b, en gardant d'abord l'ordre du + long et en ajoutant à la fin celles du + court
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

// fonction utilisé par compare pour afficher un élément d'un des 2 fichiers
function compareShowOneElt(string $key, array $file, array $keypath, string $filepath): void {
  echo '<td>';
  if (!array_key_exists($key, $file))
    echo "<i>non défini</i>";
  elseif (is_null($file[$key]))
    echo "<i>null</i>";
  elseif (is_scalar($file[$key]))
    echo $file[$key];
  elseif (is_array($file[$key])
    && (strlen($json = json_encode($file[$key], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)) < 60))
      echo "<code>$json</code>";
  else {
    $href = "?action=ypath&amp;file=$filepath&amp;ypath=".urlencode(implode('/',array_merge($keypath,[$key])));
    echo "<b>type <a href='$href'>",gettype($file[$key]),"</a></b>";
  }
  echo '</td>';
}

// chaque appel doit afficher 0, 1 ou plusieurs lignes du tableau comparatif, renvoie des stats
function compare(array $file1, array $file2, callable $fneq=null, array $path=[], array $stat=['diff'=>0,'tot'=>0]): array {
  if (!$path) {
    echo "<table border=1><th></th><th>$_GET[file1]</th><th>$_GET[file2]</th>\n";
  }
  foreach (union_keys($file1, $file2) as $key) {
    if (isset($file1[$key]) && is_array($file1[$key]) && isset($file2[$key]) && is_array($file2[$key])) {
      $stat = compare($file1[$key], $file2[$key], $fneq, array_merge($path, [$key]), $stat);
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
    printf("%d/%d différents soit %.0f %%<br>\n", $stat['diff'], $stat['tot'], $stat['diff']/$stat['tot']*100);
  }
  return $stat;
}
if (0) { // Test de la fonction compare
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
  compare($doc1, $doc2);
  die("Fin test compare()");
}

// Test si 2 noms de communes sont identiques en supprimant éventuellement l'article en début du nom
function fneqArticle(?string $a, ?string $b): bool {
  static $articles = ['Le ','La ','Les '];
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
  compare($file1, $file2, 'fneqArticle');
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

die("Aucune commande $_GET[action]\n");
