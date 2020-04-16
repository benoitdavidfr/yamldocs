<?php
/*PhpDoc:
name: grpmvts.inc.php
title: grpmvts.inc.php - définition de la classe GroupMvts
doc: |
  La classe GroupMvts permet de regrouper des mouvements élémentaires correspondant à une évolution sémantique
  et de les exploiter.
journal: |
  16/4/2020:
    - amélioration de la doc
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
    //echo Yaml::dump(['$this'=> $this->asArray()], 99, 2);
    // dans les communes nouvelles, un même id est utilisé pour le chef-lieu et la commune déléguée
    // Il faut donc intégrer le type dans la clé
    $factAv = [];
    /*[ {id_avant}=> [
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
    */
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
    
    if ($trace = $trace->is(['mod'=> $this->mod]))
      echo Yaml::dump(['$factAv'=> $factAv], 4, 2);
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
    
    if ($trace)
      echo Yaml::dump(['$factAv2'=> $factAv2], 3, 2);

    if ($trace)
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
  
  function buildEvol(Base $coms, Criteria $trace): array {
    {/*PhpDoc: methods
    name: buildEvol
    title: "function buildEvol(Base $coms, Criteria $trace): array - Fabrique une évolution sémantique à partir d'un groupe de mvts et met à jour la base des communes"
    */}
    switch($this->mod) {
      case '10': { // Changement de nom
        if (count($this->mvts) <> 1) {
          echo Yaml::dump(['factorAvant'=> $this->factorAvant($trace)]);
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
        foreach ($this->factorAvant($trace) as $id_av => $avant) {
          $evol['input'][$id_av] = ['name'=> $avant['name']];
          foreach ($avant['après'] as $id_ap => $après) {
            $evol['output'][$id_ap] = ['name'=> $après['name']];
            $coms->$id_ap = $evol['output'][$id_ap];
          }
        }
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
        //echo Yaml::dump(['factorAvant'=> $this->factorAvant()]);
        $factAv = $this->factorAvant($trace);
        $idChefLieuAv = null;
        foreach ($factAv as $id_av => $avant) {
          if ($avant['type']=='COM') {
            $idChefLieuAv = $id_av;
            $evol['input'][$id_av] = ['name'=> $avant['name']];
          }
        }
        if (!$idChefLieuAv) {
          return [
              'mod'=> $this->mod,
              'label'=> $this->label,
              'date'=> $this->date,
              'ALERTE'=> "Erreur: idChefLieuAv non trouvé ligne ".__LINE__,
              'input'=> $this->asArray(),
            ];
        }
        foreach ($factAv as $id_av => $avant) {
          if ($avant['type']=='COMA') {
            $evol['input'][$idChefLieuAv]['associées'][$id_av] = ['name'=> $avant['name']];
          }
          foreach ($avant['après'] as $id_ap => $après) {
            if ($après['type']=='COM')
              $evol['output'][$id_ap] = ['name'=> $après['name']];
            else
              $evol['output'][$idChefLieuAv]['associées'][$id_ap] = ['name'=> $après['name']];
          }
          foreach ($avant['après'] as $id_ap => $après) {
            if ($après['type']=='COM')
              $coms->$id_ap = $evol['output'][$id_ap];
            else
              $coms->$id_ap = ['associéeA'=> $idChefLieuAv];
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
        foreach ($this->factorAvant($trace) as $id_av => $avant) {
          $evol['input'][$id_av] = ['name'=> $avant['name']];
          if (count($avant['après']) == 1) { // ident du chefLieu
            $idChefLieu = array_keys($avant['après'])[0];
            $evol['output'][$idChefLieu] = ['name'=> $avant['après'][$idChefLieu]['name']];
          }
        }
        foreach ($this->factorAvant($trace) as $id_av => $avant) {
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
        foreach ($this->factorAvant($trace) as $id_av => $avant) {
          if ($avant['type'] == 'COM') {
            $idChefLieu = $id_av;
            $evol['input'][$id_av] = ['name'=> $avant['name']];
          }
        }
        foreach ($this->factorAvant($trace) as $id_av => $avant) {
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


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


require_once __DIR__.'/../../vendor/autoload.php';

// Tests unitaires des classe Verbose et Base
echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>test Base</title></head><body>\n";

if (!isset($_GET['action'])) {
  echo "<a href='?action=TestSwapKeysInArray'> Test de la fonction swapKeysInArray()</a><br>\n";
  echo "<a href='?action=TestCountLeaves'> Test de la fonction countLeaves()</a><br>\n";
  die();
}
