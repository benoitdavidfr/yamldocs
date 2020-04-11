<?php
/*PhpDoc:
name: index.php
title: index.php - diverses actions evolcoms
doc: |
  Définition de différentes actions accessibles par le Menu
journal: |
  11/4/2020:
    - extraction des classes Base et Criteria dans base.inc.php
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

/*PhpDoc: screens
name: Menu
title: Menu - permet d'exécuter les différentes actions définies
*/
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

// convertit un enregistrement txt en csv
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

{/*PhpDoc: classes
name: GroupMvts
title: GroupMvts - Groupe de mvts, chacun correspond à une évolution sémantique distincte
doc: |
  Les groupes sont générés par la méthode statique buildGroups() qui en produit un ensemble à partir d'un ens. de mvts
  élémentaires ; cette méthode est indépendante de la sémantique du mouvement.
  Ces groupes sont ensuite transformés en évolutions par la méthode buildEvol() qui interprète la sémantique du fichier INSEE
  des mouvements.
  La méthode asArray() exporte un groupe comme array Php afin notamment permettre de le visualiser en Yaml ou en JSON.
methods:
*/}
class GroupMvts {
  protected $mod; // code de modifications
  protected $label; // étiquette des modifications
  protected $date; // date d'effet des modifications
  protected $mvts; // [['avant/après'=>['type'=> type, 'id'=> id, 'name'=>name]]]
  
  static function buildGroups(array $mvtcoms): array {
    {/*PhpDoc: methods
    name: buildGroups
    title: "static function buildGroups(array $mvtcoms): array - Regroupement d'un ens. de mvts élémentaires en un ens. de groupes de mvts"
    doc: |
      L'algorithme consiste à considérer le graphe dont les sommets sont constitués des codes INSEE de commune
      et les arêtes l'existence d'un mvt entre 2 codes.
      Les groupes de mvts sont les parties connexes de ce graphe.
      L'avantage de cet algorithme est qu'il est indépendant de la sémantique des mod.
      Les mvts élémentaires initiaux doivent être du même mod et avoir la même date d'effet.
    */}
    $result = [];
    while ($mvtcoms) { // j'itère tant qu'il reste des mvts dans l'ensemble des mvts en entrée
      $comConcerned = []; // liste des communes concernées par le groupe de mvts que je construis
      // j'initialise la liste des communes concernées avec celles de la première arrête qui n'est pas une boucle
      foreach ($mvtcoms as $i => $mvt) {
        if ($mvt['avant']['id'] <> $mvt['après']['id']) {
          $comConcerned[$mvt['avant']['id']] = 1;
          $comConcerned[$mvt['après']['id']] = 1;
          $mod = $mvt['mod'];
          break;
        }
      }
      //echo Yaml::dump(['aggrMvtsCom::$comConcerned'=> array_keys($comConcerned)], 4, 2);
      if (!$comConcerned) { // Si je n'ai trouvé aucun arc non boucle cela veut dire que je n'ai que des boucles
        // dans ce cas chaque boucle correspond à une partie connexe
        foreach ($mvtcoms as $i => $mvt)
          $result[] = new GroupMvts([$mvt]);
        //echo Yaml::dump(['GroupMvts::buildGroups::$result'=> $result], 4, 2);
        return $result;
      }
      // Sinon, ici j'ai initialisé $comConcerned avec 2 communes et $mod avec une valeur
      // puis j'ajoute à $comConcerned les mvts du même mod et dont un des 2 id appartient à $comConcerned
      // et au fur et à mesure j'ajoute à $groupOfMvts la liste des mvts ainsi sélectionnés
      $groupOfMvts = []; // liste des mvts appartenant au groupe courant
      $done = false;
      while (!$done) { // je boucle ttq j'ajoute au moins un nouveu mt au groupe
        $done = true;
        foreach ($mvtcoms as $i => $mvt) {
          //echo Yaml::dump(["mvt $i"=> $mvt]);
          if (isset($comConcerned[$mvt['avant']['id']]) || isset($comConcerned[$mvt['après']['id']])) {
            $groupOfMvts[] = $mvt;
            $comConcerned[$mvt['avant']['id']] = 1; // ajout aux communes concernées
            $comConcerned[$mvt['après']['id']] = 1; // ajout aux communes concernées
            unset($mvtcoms[$i]); // je supprime un mvt de l'ensemble en entrée ce qui garantit que je ne boucle pas
            $done = false;
          }
        }
      }
      //echo Yaml::dump(['GroupMvts::buildGroups'=> $groupOfMvts], 4, 2);
      $result[] = new GroupMvts($groupOfMvts);
    }
    return $result;
  }
  
  function __construct(array $groupOfMvts) {
    //echo Yaml::dump(['GroupMvts::__construct::$groupOfMvts'=> $groupOfMvts], 4, 2);
    $this->mod = $groupOfMvts[0]['mod'];
    $this->label = $groupOfMvts[0]['label'];
    $this->date = $groupOfMvts[0]['date_eff'];
    $this->mvts = [];
    foreach ($groupOfMvts as $mvt) {
      $this->mvts[] = [
        'avant'=> $mvt['avant'],
        'après'=> $mvt['après'],
      ];
    }
  }
  
  function asArray(): array {
    $array = [
      'mod'=> $this->mod,
      'label'=> $this->label,
      'date'=> $this->date,
      'mvts'=> [],
    ];
    foreach ($this->mvts as $mvt) {
      $array['mvts'][] = [
        'avant'=> $mvt['avant'],
        'après'=> $mvt['après'],
      ];
    }
    return $array;
  }
  
  // factorisation des mvts sur l'avant
  private function factorAvant(): array {
    $result = []; // [ {id_avant}=> ['type'=> type_avant, 'name'=> name_avant, 'après'=> [après]]]
    foreach ($this->mvts as $mvt) {
      if (!isset($result[$mvt['avant']['id']])) {
        $result[$mvt['avant']['id']] = [
          'type'=> $mvt['avant']['type'],
          'name'=> $mvt['avant']['name'],
          'après'=> [
            $mvt['après']['id'] => [
              'type'=> $mvt['après']['type'],
              'name'=> $mvt['après']['name'],
            ],
          ],
        ];
      }
      else {
        $result[$mvt['avant']['id']]['après'][$mvt['après']['id']] = [
          'type'=> $mvt['après']['type'],
          'name'=> $mvt['après']['name'],
        ];
      }
    }
    //echo Yaml::dump(['factorAvant()'=> $result], 3, 2);
    return $result;
  }
  
  // Fabrique une évolution sémantique à partir d'un groupe de mvts et met à jour la base des communes
  function buildEvol(Base $coms, Criteria $trace): array {
    switch($this->mod) {
      case '10': { // Changement de nom
        if (count($this->mvts) <> 1) {
          //throw new Exception("Erreur: Changement de nom sur plusieurs éléments - Je ne sais pas interpéter");
          return [
            'mod'=> $this->mod,
            'label'=> $this->label,
            'date'=> $this->date,
            'ALERTE'=> "Erreur: Changement de nom sur plusieurs éléments ligne ".__LINE__,
            'input'=> $this->asArray(),
          ];
        }
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [ $this->mvts[0]['avant']['id']=> ['name'=> $this->mvts[0]['avant']['name']] ],
          'output'=> [ $this->mvts[0]['après']['id']=> ['name'=> $this->mvts[0]['après']['name']] ],
        ];
        // Chgt de nom dans la base
        $id_av = $this->mvts[0]['après']['id'];
        $coms->$id_av = ['name' => $this->mvts[0]['après']['name']];
        return $evol;
      }

      case '20': { // Création
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [],
          'output'=> [],
        ];
        foreach ($this->factorAvant() as $id_av => $avant) {
          $evol['input'][$id_av] = ['name'=> $avant['name']];
          foreach ($avant['après'] as $id_ap => $après)
            $evol['output'][$id_ap] = ['name'=> $après['name']];
        }
        // création dans la base
        //$coms[$mvt0['après']['id']] = ['name'=> $mvt0['après']['name']];
        return $evol;
      }
        
      case '21': { // Rétablissement - je suppose qu'il s'agit d'une défusion
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [],
          'output'=> [],
        ];
        foreach ($this->factorAvant() as $id_av => $avant) {
          $evol['input'][$id_av] = ['name'=> $avant['name']];
          foreach ($avant['après'] as $id_ap => $après) {
            $evol['output'][$id_ap] = ['name'=> $après['name']];
            // Création dans la base des communes qui n'existaient pas déjà
            if ($id_ap <> $id_av)
              $coms->$id_ap = ['name'=> $après['name']];
          }
        }
        return $evol;
      }
 
      case '30': { // Suppression
        // ex de Pierrelez (77362) supprimée en 1949 et son territoire a été partagé entre de Cerneux et Sancy-lès-Provins.  
        // https://fr.wikipedia.org/wiki/Pierrelez
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [],
          'output'=> [],
        ];
        $deleted = [];
        foreach ($this->mvts as $mvt) {
          $id_av = $mvt['avant']['id'];
          $evol['input'][$id_av] = ['name'=> $mvt['avant']['name']];
          if ($id_av <> $mvt['après']['id']) {
            $evol['output'][$mvt['après']['id']] = ['name'=> $mvt['après']['name']];
            if (!isset($deleted[$id_av])) {
              unset($coms->$id_av);
              $deleted[$id_av] = 1;
            }
          }
        }
        return $evol;
      }
    
      case '31': { // Fusion simple
        if (count($this->mvts) == 1)
          throw new Exception("Erreur: Fusion simple sur un seul élément ligne ".__LINE__);
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [],
          'output'=> [ $this->mvts[0]['après']['id']=> ['name'=> $this->mvts[0]['après']['name']] ],
        ];
        foreach ($this->mvts as $mvt) {
          $id_av = $mvt['avant']['id'];
          $evol['input'][$id_av] = ['name'=> $mvt['avant']['name']];
          // Suppressions dans la base des communes fusionnées sauf celle résultant de la fusion
          if ($id_av <> $mvt['après']['id'])
            unset($coms->$id_av);
        }
        //echo Yaml::dump(['$evol'=> $evol], 99, 2);
        return $evol;
      }

      case '32': { // Création de commune nouvelle
        if (count($grpMvtsCom) == 1)
          throw new Exception("Erreur: Création de commune nouvelle sur un seul élément");
        $evol = [
          'mod'=> $mvt0['mod'],
          'label'=> $mvt0['label'],
          'date'=> $mvt0['date_eff'],
          'input'=> [],
          'output'=> [],
        ];
        foreach ($grpMvtsCom as $mvt) {
          if ($mvt['avant']['type'] == 'COM') {
            $idChefLieu['avant'] = $mvt['avant']['id'];
            $evol['input'][$idChefLieu['avant']] = ['name'=> $mvt['avant']['name']];
          }
          if ($mvt['après']['type'] == 'COM') {
            $idChefLieu['après'] = $mvt['après']['id'];
            $evol['output'][$idChefLieu['après']] = ['name'=> $mvt['après']['name']];
          }
        }
      
        foreach ($grpMvtsCom as $mvt) {
          if ($mvt['avant']['type'] == 'COMD') {
            $evol['input'][$idChefLieu['avant']]['déléguées'][$mvt['avant']['id']] = ['name'=> $mvt['avant']['name']];
          }
          if ($mvt['après']['type'] == 'COMD') {
            $evol['output'][$idChefLieu['après']]['déléguées'][$mvt['après']['id']] = ['name'=> $mvt['après']['name']];
            $coms[$mvt['après']['id']] = ['déléguéeDe' => $idChefLieu['après']];
          }
        }
        //echo Yaml::dump(['$evol'=> $evol], 99, 2);
        unset($coms[$idChefLieu['avant']]);
        $coms[$idChefLieu['après']] = $evol['output'][$idChefLieu['après']];
        return $evol;
      }
    
      case '33': { // Fusion association
        if (count($this->mvts) == 1)
          throw new Exception("Erreur: Fusion association sur un seul élément ligne ".__LINE__);
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [],
          'output'=> [],
        ];
        //echo Yaml::dump(['factorAvant'=> $this->factorAvant()]);
        foreach ($this->factorAvant() as $id_av => $avant) {
          $evol['input'][$id_av] = ['name'=> $avant['name']];
          if (count($avant['après']) == 1) { // ident du chefLieu
            $idChefLieu = array_keys($avant['après'])[0];
            $evol['output'][$idChefLieu] = ['name'=> $avant['après'][$idChefLieu]['name']];
          }
        }
        foreach ($this->factorAvant() as $id_av => $avant) {
          if (count($avant['après']) <> 1) {
            foreach ($avant['après'] as $id_ap => $après) {
              if ($id_ap == $id_av) {
                $evol['output'][$idChefLieu]['associées'][$id_ap] = ['name'=> $après['name']];
                $coms->$id_ap = ['associéeA'=> $idChefLieu];
              }
            }
          }
        }
        $coms->$idChefLieu = $evol['output'][$idChefLieu];
        //die(Yaml::dump(['evol'=> $evol], 3));
        return $evol;
      }

      case '34': { // Transformation de fusion association en fusion simple ou suppression de communes déléguées
        // trouver la commune de rattachement
        $rttchmnt = []; // mvt correspondant à la commune de rattachement
        foreach ($this->mvts as $mvt) {
          if ($mvt['avant']['type']=='COM')
            $rttchmnt = $mvt;
        }
        if (!$rttchmnt)
          throw new Exception("Erreur ligne ".__LINE__);
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [ $rttchmnt['avant']['id'] => ['name'=> $rttchmnt['avant']['name']]],
          'output'=> [ $rttchmnt['après']['id'] => ['name'=> $rttchmnt['après']['name']]],
        ];
        foreach ($this->mvts as $mvt) {
          $id_av = $mvt['avant']['id'];
          if ($mvt['avant']['type'] == 'COMA') {
            $evol['input'][$rttchmnt['avant']['id']]['associés'] = [$mvt['avant']['id'] => ['name'=> $mvt['avant']['name']]];
            unset($coms->$id_av);
          }
          elseif ($mvt['avant']['type'] == 'COMD') {
            $evol['input'][$rttchmnt['avant']['id']]['délégués'] = [$mvt['avant']['id'] => ['name'=> $mvt['avant']['name']]];
            unset($coms->$id_av);
          }
        }
        $id_rttchmnt = $rttchmnt['après']['id'];
        $coms->$id_rttchmnt = ['name'=> $rttchmnt['après']['name']];
        return $evol;
      }

      case '41': { // Changement de code dû à un changement de département
        if (count($this->mvts) <> 1)
          throw new Exception("Erreur: Changement de code (41) sur plusieurs éléments ligne ".__LINE__);
        $mvt0 = $this->mvts[0];
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [ $mvt0['avant']['id']=> ['name'=> $mvt0['avant']['name']] ],
          'output'=> [ $mvt0['après']['id']=> ['name'=> $mvt0['après']['name']] ],
        ];
        // Chgt de du code dans la base
        $id_av = $mvt0['avant']['id'];
        unset($coms->$id_av);
        $id_ap = $mvt0['après']['id'];
        $coms->$id_ap = ['name' => $mvt0['après']['name']];
        return $evol;
      }

      case '50': { // Changement de code dû à un transfert de chef-lieu
        $evol = [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'input'=> [],
          'output'=> [],
        ];
        //echo Yaml::dump(['factorAvant'=> $this->factorAvant()]);
        foreach ($this->factorAvant() as $id_av => $avant) {
          if ($avant['type'] == 'COM') {
            $idChefLieu = $id_av;
            $evol['input'][$id_av] = ['name'=> $avant['name']];
          }
        }
        foreach ($this->factorAvant() as $id_av => $avant) {
          if ($avant['type'] <> 'COM') {
            $evol['input'][$idChefLieu]['associées'][$id_av] = ['name'=> $avant['name']];
          }
          foreach ($avant['après'] as $id_ap => $après) {
            if ($après['type']=='COM')
              $chefLieu_ap = ['id'=> $id_ap, 'name'=> $après['name']];
            else
              $assoc_ap = ['id'=> $id_ap, 'name'=> $après['name']];
          }
          $evol['output'][$chefLieu_ap['id']]['name'] = $chefLieu_ap['name'];
          $evol['output'][$chefLieu_ap['id']]['associées'][$assoc_ap['id']] = ['name'=> $assoc_ap['name']];
        }
        foreach ($evol['output'] as $idChefLieu => $chefLieu) {
          $coms->$idChefLieu = $chefLieu;
          foreach ($chefLieu['associées'] as $ida => $noma) {
            $coms->$ida = ['associéeA'=> $idChefLieu];
          }
        }
        return $evol;
      }
    
      case '70': { // Transformation de commune associée en commune déléguée
        return [
          'mod'=> $this->mod,
          'label'=> $this->label,
          'date'=> $this->date,
          'ALERTE'=> "Erreur ".__LINE__,
          'input'=> $this->asArray(),
        ];
      }
    
      default:
        throw new Exception("mod $this->mod non traité ligne ".__LINE__);
    }
  }
};

{/*PhpDoc: screens
name: genEvols
title: genEvols - génération du fichier des évolutions et enregistrement d'un fichier d'état
*/}
if ($_GET['action'] == 'genEvols') { // génération du fichier des évolutions et enregistrement d'un fichier d'état
  $datefin = '2000-01-01';
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>genEvols</title></head><body><pre>\n";
  $trace = new Criteria([]); // aucun critère, tout est affiché
  //$trace = new Criteria(['mod'=> ['not'=> ['10','20','21','30','31','33','34','41','50']]]);
  //$trace = new Criteria(['mod'=> ['34']]); 
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
        'label'=> Mvt::ModVals[$rec['mod']],
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
  echo "<pre>headers="; print_r($headers); echo "</pre>\n";
  while($record = fgetcsv($file, 0, $sep)) {
    echo "<pre>record="; print_r($record); echo "</pre>\n";
    $rec = [];
    foreach ($headers as $i => $header) {
      $rec[strtolower($header)] = $_GET['format'] == 'csv' ? $record[$i] : utf8_encode($record[$i]);
    }
    echo "<pre>rec="; print_r($rec); echo "</pre>\n";
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

function is_scalar2($v) { return is_scalar($v) || is_null($v); }

// renvoie l'union des clés triée
function union_keys(array $seta, array $setb): array {
  foreach ($setb as $kb => $b)
    if (!isset($seta[$kb]))
      $seta[$kb] = $b;
  ksort($seta);
  return array_keys($seta);
}

function compare(array $file1, array $file2, $path=[]): void {
  foreach (union_keys($file1, $file2) as $key) {
    //echo "key=$key<br>\n";
    if (isset($file1[$key]) && isset($file2[$key])) {
      if (is_array($file1[$key]) && is_array($file2[$key]))
        compare($file1[$key], $file2[$key], array_merge($path, [$key]));
      elseif (is_scalar2($file1[$key]) && is_scalar2($file2[$key])) {
        if ($file1[$key] <> $file2[$key])
          echo '<tr><td>',implode('/', $path),"/$key</td><td>",$file1[$key],"</td><td>",$file2[$key],"</td></tr>\n";
      }
      else
        echo '<tr><td>',implode('/', $path),"/$key</td>",
          "<td><b>type ",gettype($value),"</b></td><td><b>type ",gettype($file2[$key]),"</b></td></tr>\n";
    }
    elseif (isset($file1[$key])) {
      $href = "?action=ypath&amp;file=$_GET[file1]&amp;ypath=".urlencode(implode('/',$path))."/$key";
      echo '<tr><td>',implode('/', $path),"/$key</td>",
        "<td><b>type <a href='$href'>",gettype($file1[$key]),"</a></b></td>",
        "<td><b>non défini</b></td></tr>\n";
    }
    elseif (isset($file1[$key])) {
      echo '<tr><td>',implode('/', $path),"/$key</td>",
        "<td><b>non défini</b></td><td><b>type ",gettype($file2[$key]),"</b></td></tr>\n";
    }
  }
}

if ($_GET['action'] == 'compare') {
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
  }
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>compare $_GET[file1] $_GET[file2]</title></head><body>\n";
  echo "<b>Comparaison de $_GET[file1] et $_GET[file2]</b><br>\n";
  $file1 = Yaml::parse(file_get_contents(__DIR__."/$_GET[file1]"));
  $file2 = Yaml::parse(file_get_contents(__DIR__."/$_GET[file2]"));
  echo "<table border=1><th></th><th>$_GET[file1]</th><th>$_GET[file2]</th>\n";
  compare($file1, $file2);
  echo "</table>\n";
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
