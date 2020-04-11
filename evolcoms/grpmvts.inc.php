<?php
/*PhpDoc:
name: grpmvts.inc.php
title: grpmvts.inc.php - définition de la classe GroupMvts
doc: |
  Définition de différentes actions accessibles par le Menu
journal: |
  11/4/2020:
    - extraction de la classe GroupMvts dans grpmvts.inc.php
classes:
*/

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

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
        echo Yaml::dump(['factorAvant'=> $this->factorAvant()]);
        $factAv = $this->factorAvant();
        foreach ($factAv as $id_av => $avant) {
          if ($avant['type']=='COM') {
            $idChefLieuAv = $id_av;
            $evol['input'][$id_av] = ['name'=> $avant['name']];
          }
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

