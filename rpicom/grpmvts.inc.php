<?php
/*PhpDoc:
name: grpmvts.inc.php
title: grpmvts.inc.php - définition de la classe GroupMvts
doc: |
  La classe GroupMvts permet de regrouper des mouvements élémentaires correspondant à une évolution sémantique
  et de les exploiter.
journal: |
  19/4/2020:
    - première version traitant tous les cas de addToRpicom()
  16/4/2020:
    - amélioration de la doc
    - prise en compte du nv format de factorAvant() dans buildEvol()
      - réécriture des mod 10, 21 et 31
      - utilisation de l'evol par défaut pour les autres, à TESTER
  14/4/2020:
    - suppression de 6 doublons dans la lecture du CSV des mouvements INSEE
    - modification de factorAvant() en introduisant des codes INSEE modifiés pour les communes déléguées
  11/4/2020:
    - extraction de la classe GroupMvts dans grpmvts.inc.php
functions:
classes:
*/

{/* Erreur de doublon sur
["34","2014-04-01","COM","49328","0","SAUMUR","Saumur","Saumur","COM","49328","0","SAUMUR","Saumur","Saumur"]
["34","2014-04-01","COM","49328","0","SAUMUR","Saumur","Saumur","COM","49328","0","SAUMUR","Saumur","Saumur"]
["34","2014-04-01","COM","49328","0","SAUMUR","Saumur","Saumur","COM","49328","0","SAUMUR","Saumur","Saumur"]
["21","1977-01-01","COM","89344","0","SAINT FARGEAU","Saint-Fargeau","Saint-Fargeau","COM","89344","0","SAINT FARGEAU","Saint-Fargeau","Saint-Fargeau"]
["33","1973-01-01","COM","86160","0","MIREBEAU","Mirebeau","Mirebeau","COM","86160","0","MIREBEAU","Mirebeau","Mirebeau"]
["31","1973-01-01","COM","89068","0","CHABLIS","Chablis","Chablis","COM","89068","0","CHABLIS","Chablis","Chablis"]
*/}

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

{/*PhpDoc: functions
name: swapKeysInArray
title: "function swapKeysInArray(string $key1, string $key2, array $array): array - intervertit l'ordre des 2 clés dans array en gardant les valeurs correspondantes"
*/}
function swapKeysInArray(string $key1, string $key2, array $array): array {
  $result = [];
  foreach ($array as $key => $value) {
    if ($key == $key1)
      $result[$key2] = $array[$key2];
    elseif ($key == $key2)
      $result[$key1] = $array[$key1];
    else
      $result[$key] = $value;
  }
  return $result;
}
if ('TestSwapKeysInArray' == $_GET['action'] ?? null) { // Test swapKeysInArray
  $array = [
    'avant' => "avant",
    '46251' => "Saint-Céré",
    '46339' => "Saint-Jean-Lagineste",
    'après' => "après",
  ];
  echo "<pre>\n";
  print_r($array);
  print_r(swapKeysInArray('46251', '46339', $array));
  die("Fin test swapKeysInArray");
}

{/*PhpDoc: functions
name: countLeaves
title: "function countLeaves(array $tree): int - compte le nombre de feuilles stockées dans $tree considéré comme un arbre où chaque array est un noeud intermédiaire"
*/}
function countLeaves(array $tree): int {
  $count = 0;
  foreach ($tree as $key => $child) {
    if (is_array($child))
      $count += countLeaves($child);
    else
      $count++;
  }
  return $count;
}
if ('TestCountLeaves' == $_GET['action'] ?? null) { // Test countLeaves() 
  class TestForCountLeaves { };
  echo countLeaves(['a'=> 1]),"<br>\n";
  echo countLeaves(['a'=> 1, 'b'=> ['c'=> 2, 'd'=> 3]]),"<br>\n";
  echo countLeaves(['a'=> null]),"<br>\n";
  echo countLeaves(['a'=> new TestForCountLeaves]),"<br>\n";
  die("Fin test countLeaves()");
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
  La méthode mvtsPattern() retourne un motif de mouvement, permettant ainsi d'identifier les différents motifs et
  d'améliorer la qualité de la programmation de buildEvol().
methods:
*/}
class GroupMvts {
  // modalités de mod et étiquette associée à chacune
  const ModLabels = [
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
  protected $mod; // code de modifications
  protected $label; // étiquette des modifications
  protected $date; // date d'effet des modifications
  protected $mvts; // [['avant/après'=>['type'=> type, 'id'=> id, 'name'=>name]]]
  
  static function readMvtsInsee(string $filepath): array { // lecture du fichier CSV INSEE des mts et tri par ordre chronologique
    $mvtcoms = []; // Liste des mvts retriée par ordre chronologique
    $mvtsUniq = []; // Utilisé pour la vérification d'unicité des enregistrements
    $file = fopen($filepath, 'r');
    $headers = fgetcsv($file);
    $nbrec = 0;
    while($record = fgetcsv($file)) { // lecture des mvts et structuration dans $mvtcoms par date d'effet
      //print_r($record);
      $json = json_encode($record, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
      if (isset($mvtsUniq[$json])) {
        //echo "Erreur de doublon sur $json\n";
        continue;
      }
      $mvtsUniq[$json] = 1;
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
    return $mvtcoms;
  }
    
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
  
  // factorisation des mvts sur l'avant utilisée par buildEvol()
  private function factorAvant(Criteria $trace): array {
    {/*PhpDoc: methods
    name: factorAvant
    title: "private function factorAvant(Criteria $trace): array - factorisation des mvts sur l'avant utilisée par buildEvol()"
    doc: |
      Dans les communes nouvelles, un même id est utilisé pour le chef-lieu et la commune déléguée
      Il faut donc intégrer le type dans la clé
      Le format de factorAvant est:
        [ {id_avant}=> [
            {type_avant}=> [
              'name'=> {name_avant},
              'après'=> [
                {id_après}=> [
                  {type_après}=> [
                    'name'=> {name_après}
                  ]
                ]
              ]
            ]
        ]]
    */}
    //echo Yaml::dump(['$this'=> $this->asArray()], 99, 2);
    $factAv = [];
    foreach ($this->mvts as $mvt) {
      $typav = $mvt['avant']['type'];
      $idav = $mvt['avant']['id'];
      $factAv[$idav][$typav]['name'] = $mvt['avant']['name'];
      $typap = $mvt['après']['type'];
      $idap = $mvt['après']['id'];
      if (isset($factAv[$idav][$typav]['après'][$idap][$typap])) {
        echo Yaml::dump(['$this'=> $this->asArray()], 99, 2);
        throw new Exception("Erreur d'écrasement dans factorAvant() sur $idav$typav/$idap$typap");
      }
      $factAv[$idav][$typav]['après'][$idap][$typap] = [ 'name'=> $mvt['après']['name'] ];
    }
    
    //if (0 && $trace->is(['mod'=> $this->mod])) echo Yaml::dump(['$factAv'=> $factAv], 4, 2);
    //return $factAv;
    
    // standardisation
    // je met en premier les règles ayant plusieurs après
    // puis je met dans l'ordre les COM puis les COMA puis les COMD puis les ARM
    $factAv2 = [];
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) > 1) && ($typav == 'COM'))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) > 1) && ($typav == 'COMA'))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) > 1) && ($typav == 'COMD'))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) > 1) && ($typav == 'ARM'))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) > 1) && !in_array($typav, ['COM','COMA','COMD','ARM']))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) == 1) && ($typav == 'COM'))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) == 1) && ($typav == 'COMA'))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) == 1) && ($typav == 'COMD'))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) == 1) && ($typav == 'ARM'))
          $factAv2[$idav][$typav] = $avant2;
    }
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if ((countLeaves($avant2['après']) == 1) && !in_array($typav, ['COM','COMA','COMD','ARM']))
          $factAv2[$idav][$typav] = $avant2;
    }

    // je met les variables après dans l'ordre des avants
    // et dans chaque partie après de la règle j'ordonne les variables par no
    $vars = []; // [ {id} => {no}]
    $no = 0;
    foreach ($factAv2 as $idav => $avant)
      $vars[$idav] = ++$no;
    foreach ($factAv2 as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        foreach (array_keys($avant2['après']) as $idap)
          if (!isset($vars[$idap]))
            $vars[$idap] = ++$no;
    }
    foreach ($factAv2 as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2) {
        $done = false;
        $compteur = 0;
        while (!$done) {
          $done = true;
          foreach (array_keys($avant2['après']) as $no => $idap) {
            if ($no <> 0) {
              if ($vars[$idap] < $vars[$idapprev]) {
                $avant2['après'] = swapKeysInArray($idapprev, $idap, $avant2['après']);
                $done = false;
                break;
              }
            }
            $idapprev = $idap;
          }
          if ($compteur++ > 1000) die("compteur explosé 1000 ligne ".__LINE__);
        }
        $factAv2[$idav][$typav] = $avant2;
      }
    }
    
    if ($trace->is(['mod'=> $this->mod]))
      echo Yaml::dump(['$factAv2'=> $factAv2], 5, 2);

    if ($trace->is(['mod'=> $this->mod]))
      echo "\n";
    return $factAv2;
  }
  
  function mvtsPattern(Criteria $trace): array {
    {/*PhpDoc: methods
    name: mvtsPattern
    title: "function mvtsPattern(Criteria $trace): array - fabrique le motif correspondant au groupe"
    */}
    $factAv = $this->factorAvant($trace);
    if ($trace->is(['mod'=> $this->mod]))
      echo Yaml::dump(['factorAvant'=> $factAv], 6, 2);
    $mvtsPat = [
      'mod'=> $this->mod,
      'label'=> $this->label,
      'nb'=> 1, // utilisé pour lister les patterns et compter leur nbre d'occurence
      'règles'=> [],
      'example'=> [
        'factAv'=> ['date'=> $this->date, '$factAv'=> $factAv],
        'group'=> $this,
      ],
    ];
    $vars = []; // [ {id} => {no}]
    $no = 0;
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2)
        if (!isset($vars[$idav]))
          $vars[$idav] = ++$no;
    }
    $suffix = ['COM'=>'', 'COMA'=>'a', 'COMD'=>'d', 'ARM'=>'m'];
    foreach ($factAv as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant2) {
        foreach($avant2['après'] as $idap => $après1) {
          foreach($après1 as $typap => $après2) {
            if (!isset($vars[$idap]))
              $vars[$idap] = ++$no;
            $idavf = 'id'.$vars[$idav].$suffix[$typav];
            $idapf = 'id'.$vars[$idap].$suffix[$typap];
            addValToArray(
              $idapf,
              $mvtsPat['règles'][$idavf]);
          }
        }
      }
    }
    if ($trace->is(['mod'=> $this->mod]))
      echo Yaml::dump(['$mvtsPat'=> $mvtsPat], 4, 2);
    return $mvtsPat;
  }
  
  // evol par défaut
  function defaultEvol(string $idChefLieuAv,string $idChefLieuAp, Base $coms, Criteria $trace): array {
    foreach ($this->factorAvant($trace) as $idav => $avant1) {
      foreach ($avant1 as $typav => $avant) {
        if ($typav == 'COM')
          $input[$idav]['name'] = $avant['name'];
        elseif ($typav == 'COMA')
          $input[$idChefLieuAv]['associées'][$idav]['name'] = $avant['name'];
        elseif ($typav == 'COMD')
          $input[$idChefLieuAv]['déléguées'][$idav]['name'] = $avant['name'];
        elseif ($typav == 'ARM')
          $input[$idChefLieuAv]['ardtMun'][$idav]['name'] = $avant['name'];
        else
          throw new Exception("Cas imprévu ligne ".__LINE__);
        // première itération uniq. sur le nom pour qu'il apparaisse en premier
        foreach ($avant['après'] as $idap => $après1) {
          foreach ($après1 as $typap => $après) {
            if ($typap == 'COM')
              $result[$idap]['name'] = $après['name'];
          }
        }
        foreach ($avant['après'] as $idap => $après1) {
          foreach ($après1 as $typap => $après) {
            if ($typap == 'COM')
              $result[$idap]['name'] = $après['name'];
            elseif ($typap == 'COMA')
              $result[$idChefLieuAp]['associées'][$idap]['name'] = $après['name'];
            elseif ($typap == 'COMD')
              $result[$idChefLieuAp]['déléguées'][$idap]['name'] = $après['name'];
            elseif ($typap == 'ARM')
              $result[$idChefLieuAp]['ardtMun'][$idap]['name'] = $après['name'];
            else
              throw new Exception("Cas imprévu ligne ".__LINE__);
          }
        }
      }
    }
    foreach (array_keys($input) as $idav) {
      if (!isset($result[$idav]))
        unset($coms->$idav);
    }
    foreach ($result as $idap => $com) {
      if ($idap <> '69123')
        $coms->$idap = $com;
    }
    return [
      'mod'=> $this->mod,
      'label'=> $this->label,
      'date'=> $this->date,
      'input'=> $input,
      'result'=> $result,
    ];
  }
  
  function buildEvol(Base $coms, Criteria $trace): array {
    {/*PhpDoc: methods
    name: buildEvol
    title: "function buildEvol(Base $coms, Criteria $trace): array - Fabrique une évolution sémantique à partir d'un groupe de mvts et met à jour la base des communes"
    */}
    
    switch($this->mod) {
      case '10': { // Changement de nom
        $factAv = $this->factorAvant($trace);
        $idChefLieu = array_keys($factAv)[0];
        return $this->defaultEvol($idChefLieu, $idChefLieu, $coms, $trace);
      }

      case '20': { // Création
        return $this->defaultEvol('PasDeChefLieu', 'PasDeChefLieu', $coms, $trace);
      }
        
      case '21': { // Rétablissement
        $factAv = $this->factorAvant(new Criteria(['not']));
        // J'utilise le principe que l'idChefLieu est clé de la première ligne et doit aussi être parmi les après
        $idChefLieu = array_keys($factAv)[0];
        $typChefLieu = array_keys($factAv[$idChefLieu])[0];
        $après = $factAv[$idChefLieu][$typChefLieu]['après'];
        if ($typChefLieu == 'ARM') { // cas particulier des scissions des ARM de Lyon (69123)
          $idLyon = '69123';
          $lyon = $coms->$idLyon;
          foreach ($après as $idap => $après1)
            foreach ($après1 as $typap => $après)
              $lyon['ardtMun'][$idap]['name'] = $après['name'];
          $coms->$idLyon = $lyon;
          return $this->defaultEvol('69123', '69123', $coms, $trace);
        }
        if (!in_array($idChefLieu, array_keys($après))) {
          return [
              'mod'=> $this->mod,
              'label'=> $this->label,
              'date'=> $this->date,
              'ALERTE'=> "Erreur: idChefLieu non trouvé ligne ".__LINE__,
              'input'=> $this->asArray(),
            ];
        }
        return $this->defaultEvol($idChefLieu, $idChefLieu, $coms, $trace);
      }
 
      case '30': { // Suppression
        return $this->defaultEvol('PasDeChefLieu', 'PasDeChefLieu', $coms, $trace);
      }
    
      case '31': { // Fusion simple
        // Ttes les règles sont de la forme {id{i}=> id0} où id0 est le la commune fusionnée
        return $this->defaultEvol('PasDeChefLieu', 'PasDeChefLieu', $coms, $trace);
      }

      case '32': { // Création de commune nouvelle
        // Le ChefLieuAp est le seul id d'arrivée qui est de type COM
        $idChefLieuAp = null;
        foreach ($this->factorAvant($trace) as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            foreach ($avant['après'] as $idap => $après1) {
              foreach ($après1 as $typap => $après) {
                if ($typap == 'COM') {
                  $idChefLieuAp = $idap;
                  break 4;
                }
              }
            }
          }
        }
        if (!$idChefLieuAp)
          return [
              'mod'=> $this->mod,
              'label'=> $this->label,
              'date'=> $this->date,
              'ALERTE'=> "Erreur: idChefLieu non trouvé ligne ".__LINE__,
              'input'=> $this->asArray(),
            ];
        return $this->defaultEvol('PasDeChefLieu', $idChefLieuAp, $coms, $trace);
      }
    
      case '33': // Fusion association
      case '34': { // Transformation de fusion association en fusion simple ou suppression de communes déléguées
        // Le ChefLieu est le seul id d'arrivée qui est de type COM
        $idChefLieu = null;
        foreach ($this->factorAvant(new Criteria(['not'])) as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            foreach ($avant['après'] as $idap => $après1) {
              foreach ($après1 as $typap => $après) {
                if ($typap == 'COM') {
                  $idChefLieu = $idap;
                  break 4;
                }
              }
            }
          }
        }
        if (!$idChefLieu)
          return [
              'mod'=> $this->mod,
              'label'=> $this->label,
              'date'=> $this->date,
              'ALERTE'=> "Erreur: idChefLieu non trouvé ligne ".__LINE__,
              'input'=> $this->asArray(),
            ];
        return $this->defaultEvol($idChefLieu, $idChefLieu, $coms, $trace);
      }

      case '41': { // Changement de code dû à un changement de département
        return $this->defaultEvol('PasDeChefLieu', 'PasDeChefLieu', $coms, $trace);
      }

      case '50': { // Changement de code dû à un transfert de chef-lieu
        // Le ChefLieuAv est le seul id avant qui est de type COM
        $idChefLieuAv = null;
        foreach ($this->factorAvant($trace) as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            if ($typav == 'COM') {
              $idChefLieuAv = $idav;
                break 2;
            }
          }
        }
        // Le ChefLieuAp est le seul id d'arrivée qui est de type COM
        $idChefLieuAp = null;
        foreach ($this->factorAvant($trace) as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            foreach ($avant['après'] as $idap => $après1) {
              foreach ($après1 as $typap => $après) {
                if ($typap == 'COM') {
                  $idChefLieuAp = $idap;
                  break 4;
                }
              }
            }
          }
        }
        if (!$idChefLieuAv || !$idChefLieuAp)
          return [
              'mod'=> $this->mod,
              'label'=> $this->label,
              'date'=> $this->date,
              'ALERTE'=> "Erreur: idChefLieu non trouvé ligne ".__LINE__,
              'input'=> $this->asArray(),
            ];
        return $this->defaultEvol($idChefLieuAv, $idChefLieuAp, $coms, $trace);
      }
    
      case '70': { // Transformation de commune associée en commune déléguée
        if (($this->date == '2020-01-01') && ($this->mvts[0]['avant']['id'] == '52064')) {
          /*$factAv:
            52064:
              COM:
                name: Bourmont-entre-Meuse-et-Mouzon
                après:
                  52224: { COMD: { name: Gonaincourt } }
            Je considère que c'est une erreur INSEE, 
            n'existe pas sur le COG du web https://www.insee.fr/fr/metadonnees/cog/commune/COM52224-gonaincourt
          */
          return [];
        }
        return $this->defaultEvol('PasDeChefLieu', 'PasDeChefLieu', $coms, $trace);
      }
    
      default:
        throw new Exception("mod $this->mod non traité ligne ".__LINE__);
    }
  }

  // traduit en étiquette pour Rpicom le type issu du fichier INSEE
  private static function linkLabel(string $type) {
    switch ($type) {
      case 'COM': return '';
      case 'COMA': return 'associéeA';
      case 'COMD': return 'déléguéeDe';
      case 'ARM': return 'ardtMunDe';
      default:
        throw new Exception("Erreur type=$type ligne ".__LINE__);
    }
  }
    
  function addToRpicom(Base $rpicom, Criteria $trace): void {
    {/*PhpDoc: methods
    name: rpicom
    title: "function addToRpicom(Base $$rpicom, Criteria $trace): void - Ajoute au RPICOM le groupe"
    */}
    $factAv = $this->factorAvant($trace);
    //$evol = $this->buildEvol(new Base('', new Criteria(['not'])), $trace);
    //echo Yaml::dump(['evol'=> $evol]);
    $rpicom->startExtractAsYaml();
    switch($this->mod) {
      case '10': { // Changement de nom
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            $rpicom->mergeToRecord($idav, [
              $this->date => [
                'évènement'=> "Changement de nom",
                'name'=> $avant['name'],
              ]
            ]);
          }
        }
        break;
      }
      
      case '20': { // Création
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            foreach ($avant['après'] as $idap => $après1) {
              foreach ($après1 as $typap => $après) {
                if ($idap <> $idav) {
                  $idCréée = $idap;
                  $nomCréée = $après['name'];
                  $crééeAPartirDe = [];
                  break 4;
                }
              }
            }
          }
        }
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            foreach ($avant['après'] as $idap => $après1) {
              foreach ($après1 as $typap => $après) {
                if ($idap == $idav) {
                  $rpicom->mergeToRecord($idav, [
                    $this->date => [
                      'contribueA'=> $idCréée,
                      'name'=> $avant['name'],
                    ]
                  ]);
                  $crééeAPartirDe[] = $idav;
                }
              }
            }
          }
        }
        $rpicom->mergeToRecord($idCréée, [
          $this->date => [
            'après'=> [
              'name'=> $nomCréée,
            ],
            'crééeAPartirDe'=> $crééeAPartirDe,
          ],
        ]);
        break;
      }
      
      case '21': { // Rétablissement
        if ($this->mvts[0]['avant']['type']=='ARM') {
          /*
          date: '1964-08-12'
          $factAv2:
            69385:
              ARM:
                name: 'Lyon 5e Arrondissement'
                après:
                  69385: { ARM: { name: 'Lyon 5e Arrondissement' } }
                  69389: { ARM: { name: 'Lyon 9e Arrondissement' } }
          */
          foreach ($factAv as $idav => $avant1) {
            foreach ($avant1 as $typav => $avant) {
              foreach ($avant['après'] as $idap => $après1) {
                foreach ($après1 as $typap => $après) {
                  if ($idap <> $idav) {
                    $idCréé = $idap;
                    $nomCréé = $après['name'];
                    $crééAPartirDe = [];
                    break 4;
                  }
                }
              }
            }
          }
          foreach ($factAv as $idav => $avant1) {
            foreach ($avant1 as $typav => $avant) {
              foreach ($avant['après'] as $idap => $après1) {
                foreach($après1 as $typap => $après) {
                  if ($idap == $idav) {
                    $rpicom->mergeToRecord($idav, [
                      $this->date => [
                        'contribueA'=> $idCréé,
                        'name'=> $avant['name'],
                        'ardtMunDe'=> 'inconnu',
                      ]
                    ]);
                    $crééAPartirDe[] = $idav;
                  }
                }
              }
            }
          }
          $rpicom->mergeToRecord($idCréé, [
            $this->date => [
              'après'=> [
                'name'=> $nomCréé,
                'ardtMunDe'=> 'inconnu',
              ],
              'crééeAPartirDe'=> $crééAPartirDe,
            ],
          ]);
          break;
        }
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            if ($typav == 'COM') {
              $idCom = $idav;
              $nameCom = $avant['name'];
            }
            elseif ($idav <> $idCom) {
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'name'=> $avant['name'],
                  self::linkLabel($typav) => $idCom,
                ]
              ]);
            }
            else { // com. dél. ayant même code que sa mère
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'name'=> $nameCom,
                  'commeDéléguée' => [
                    'name'=> $avant['name'],
                  ]
                ]
              ]);
            }
          }
        }
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            foreach ($avant['après'] as $idap => $après1) {
              foreach($après1 as $typap => $après) {
                if (!isset($factAv[$idap])) { // $idap apparait sans être présente dans les avants
                  $rpicom->mergeToRecord($idap, [ $this->date => ['rétablieDe'=> $idCom] ]);
                }
              }
            }
          }
        }
        break;
      }
      
      case '30': { // Suppression
        /*
        date: '1968-03-02'
        $factAv2:
          '08227':
            COM:
              name: Hocmont
              après:
                '08203': { COM: { name: Guignicourt-sur-Vence } }
                '08454': { COM: { name: Touligny } }
          '08203':
            COM:
              name: Guignicourt-sur-Vence
              après:
                '08203': { COM: { name: Guignicourt-sur-Vence } }
          '08454':
            COM:
              name: Touligny
              après:
                '08454': { COM: { name: Touligny } }
        */
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            if (!isset($avant['après'][$idav])) { // $idav est la commune supprimée
              $idSupprimée = $idav;
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'seDissoutDans'=> array_keys($avant['après']),
                  'name'=> $avant['name']
                ]
              ]);
            }
            else {
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'reçoitUnePartieDe'=> $idSupprimée,
                  'name'=> $avant['name']
                ]
              ]);
            }
          }
        }
        break;
      }
      
      case '32': { // Création de commune nouvelle
        // chercher le nouveau chef-lieu qui est le seul type=='COM' à droite, je l'appelle $idChefLieu
        $idChefLieu = null;
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            foreach ($avant['après'] as $idap => $après1) {
              foreach ($après1 as $typap => $après) {
                if ($typap == 'COM') { $idChefLieu = $idap; break 4; }
              }
            }
          }
        }
        if (!$idChefLieu)
          throw new Exception("idChefLieu non trouvé ligne ".__LINE__);
 
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            if (($idav == $idChefLieu) && ($typav == 'COM')) {
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'évènement'=> "Devient commune nouvelle",
                  'name'=> $avant['name']
                ]
              ]);
            }
            else {
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'évènement'=> "Devient commune déléguée",
                  'name'=> $avant['name']
                ]
              ]);
            }
          }
        }
        break;
      }
      
      case '31': { // Fusion simple
        /*$group:
          mod: '31'
          label: 'Fusion simple'
          date: '1947-08-27'
        $factAv2:
          14485:
            COM:
              name: Ouilly-le-Basset
              après:
                14764: { COM: { name: 'Pont-d''Ouilly' } }
          14612:
            COM:
              name: 'Saint-Marc-d''Ouilly'
              après:
                14764: { COM: { name: 'Pont-d''Ouilly' } }
        */
        $idav = array_keys($factAv)[0];
        $typav = array_keys($factAv[$idav])[0];
        $idChefLieu = array_keys($factAv[$idav][$typav]['après'])[0];
        $typChefLieu = array_keys($factAv[$idav][$typav]['après'][$idChefLieu])[0];
        $nomChefLieu = $factAv[$idav][$typav]['après'][$idChefLieu][$typChefLieu]['name'];
        $fusionnées = [];
        // 2 cas: soit la commune fusionnée reprend l'id d'une des communes d'origine, soit elle en prend un nouveau
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            if ($idav <> $idChefLieu) {
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'fusionneDans'=> $idChefLieu,
                  'name'=> $avant['name'],
                ]
              ]);
              $fusionnées[] = $idav;
            }
          }
        }
        if (isset($factAv[$idChefLieu])) // cas où la commune fusionnée reprend l'id d'une des c. d'origine
          $rpicom->mergeToRecord($idChefLieu, [
            $this->date => [
              'reçoitEnFusionSimple'=> $fusionnées,
              'name'=> (array_values($factAv[$idChefLieu])[0])['name'],
            ]
          ]);
        else // cas où la commune fusionnée prend un nouvel id
          $rpicom->mergeToRecord($idChefLieu, [
            $this->date => [
              'créationParFusionSimpleDe'=> $fusionnées,
            ]
          ]);
        break;
      } 
      
      case '33': { // Fusion association
        // identification du chef-lieu
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            $idap = array_keys($avant['après'])[0];
            if (($typav == 'COM') && (count($avant['après'])==1) && ($idap == $idav)) { // le Chef Lieu
              $idChefLieu = $idav;
            }
          }
        }
        if (!isset($idChefLieu))
          throw new Exception("idChefLieu non trouvé ligne ".__LINE__);
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            $idap = array_keys($avant['après'])[0];
            if (($typav == 'COM') && (count($avant['après'])==1) && ($idap == $idav)) { // le Chef Lieu
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'évènement'=> "Accueille des associées",
                  'name'=> $avant['name'],
                ]
              ]);
            }
            elseif (($typav == 'COM') && (count($avant['après'])==1)) { // fusion ss association
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'fusionneAvec'=> $idChefLieu,
                  'name'=> $avant['name'],
                ]
              ]);
            }
            elseif ($typav == 'COMA') { // reste associée
            }
            else { // $idav s'associe
              foreach ($avant['après'] as $idap => $après1) {
                foreach ($après1 as $typap => $après) {
                  if ($typap == 'COMA') {
                    $nameComa = $après['name'];
                  }
                }
              }
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'après'=> [
                    'name'=> $nameComa,
                    'associéeA'=> $idChefLieu,
                  ],
                  "s'associeA"=> $idChefLieu,
                  'name'=> $avant['name'],
                ]
              ]);
            }
          }
        }
        break;
      }
      
      case '34': { // Suppression associée ou déléguée
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            if ($typav == 'COM') {
              $idCom = $idav;
              $com = [
                'évènement'=> "absorbe certaibes de ses c. associées ou déléguées",
                'name'=> $avant['name']
              ];
            }
            elseif ($idav <> $idCom) {
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'absorbéePar'=> $idCom,
                  'name'=> $avant['name'],
                  self::linkLabel($typav) => $idCom,
                ]
              ]);
            }
            else {
              $com['commeDéléguée'] = [ 'name'=> $avant['name'] ];
            }
          }
        }
        $rpicom->mergeToRecord($idCom, [ $this->date => $com ]);
        break;
      }
      
      case '41' : { // Changement de code dû à un changement de département
        $idav = array_keys($factAv)[0];
        $typav = array_keys($factAv[$idav])[0];
        $idap = array_keys($factAv[$idav][$typav]['après'])[0];
        $typap = array_keys($factAv[$idav][$typav]['après'][$idap])[0];
        $rpicom->mergeToRecord($idap, [ $this->date => ['changeDeDépartementEtAvaitPourCode'=> $idav] ]);
        $rpicom->$idav = [ $this->date => [
          'changeDeDépartementEtPrendLeCode'=> $idap,
          'name'=> $factAv[$idav][$typav]['name'],
        ]];
        break;
      }
      
      case '50': { // Changement de code dû à un transfert de chef-lieu
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            if ($typav == 'COM') {
              $idChefLieuAv = $idav;
              $nameChefLieuAv = $avant['name'];
            }
            foreach ($avant['après'] as $idap => $après1) {
              foreach ($après1 as $typap => $après) {
                if ($typap == 'COM') {
                  $idChefLieuAp = $idap;
                  $nameChefLieuAp = $après['name'];
                  break 4;
                }
              }
            }
          }
        }
        if (!$idChefLieuAv || !$idChefLieuAp)
          throw new Exception("idChefLieu non trouvé ligne ".__LINE__);
        foreach ($factAv as $idav => $avant1) {
          foreach ($avant1 as $typav => $avant) {
            if ($typav == 'COM') {
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'évènement'=> "Devient nouveau Chef-lieu",
                  'name'=> $avant['name']
                ]
              ]);
            }
            elseif ($typav == 'COMA') {
              $rpicom->mergeToRecord($idav, [
                $this->date => [
                  'évènement'=> "Transfert de chef-lieu",
                  'name'=> $avant['name'],
                  'associéeA'=> $idChefLieuAv,
                ]
              ]);
            }
            else
              throw new Exception("Cas non prévu ligne ".__LINE__);
          }
        }
        break;
      }
      
      case '70': { // Transformation de commune associée en commune déléguée
        /*
          date: '2020-01-01'
          mvts:
            - avant: { type: COM, id: '52064', name: Bourmont-entre-Meuse-et-Mouzon }
              après: { type: COMD, id: '52224', name: Gonaincourt }
          Je considère que c'est une erreur INSEE.
        */
        break;
      }
      
      default: {
        throw new Exception("mod $this->mod non traité ligne ".__LINE__);
      }
    }
    
    if ($trace->is(['mod'=> $this->mod]))
      $rpicom->showExtractAsYaml(5, 2);
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


require_once __DIR__.'/../../vendor/autoload.php';

// Tests unitaires des classe Verbose et Base
echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>test Base</title></head><body>\n";

if (!isset($_GET['action'])) {
  echo "<a href='?action=TestSwapKeysInArray'> Test de la fonction swapKeysInArray()</a><br>\n";
  echo "<a href='?action=TestCountLeaves'> Test de la fonction countLeaves()</a><br>\n";
  die();
}
