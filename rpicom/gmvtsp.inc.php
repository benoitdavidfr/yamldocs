<?php
/*PhpDoc:
name: gmvtsp.inc.php
title: gmvtsp.inc.php - définition de la classe GMvtsP (ABANDONNE)
doc: |
  La classe GMvtsP regroupe des mouvements élémentaires correspondant à une même date, un même code mod
  et un même code INSEE après, et de les exploiter.
  Son intérêt n'est pas démontré.
journal: |
  19/4/2020:
    - création
functions:
classes:
*/

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class GMvtsP {
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
      Regroupement pour une date et une modif donnée de tous les mvts ayant même code INSEE après
    */}
    $results = [];
    foreach ($mvtcoms as $i => $mvt) {
      addValToArray($mvt, $results[$mvt['après']['id']]);
    }
    foreach ($results as $k => $groupOfMvts) {
      $results[$k] = new self($groupOfMvts);
    }
    return $results;
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
  
  function pattern(): array {
    $id0 = $this->mvts[0]['après']['id'];
    $vars = [$id0 => 0];
    foreach ($this->mvts as $mvt) {
      if ($mvt['avant']['id'] == $id0)
        addValToArray($mvt['avant']['type'], $factAp[$mvt['après']['type']]['id0']);
    }
    foreach ($this->mvts as $mvt) {
      if ($mvt['avant']['id'] <> $id0) {
        if (!isset($vars[$mvt['avant']['id']]))
          $vars[$mvt['avant']['id']] = count($vars);
        addValToArray($mvt['avant']['type'], $factAp[$mvt['après']['type']]['id'.$vars[$mvt['avant']['id']]]);
      }
    }
    return [
      'mod'=> $this->mod,
      'label'=> $this->label,
      'factAp'=> $factAp,
      'example'=> $this->asArray(),
    ];
  }

  function addToRpicom(Base $rpicom, Criteria $trace): void {
    $idap = $this->mvts[0]['après']['id'];
    if ($trace->is(['mod'=> $this->mod]))
      $rpicom->startExtractAsYaml();;
    $rpicom->mergeToRecord($idap, [
      $this->date => [
        'mod'=> $this->mod,
        'label'=> $this->label,
        'mvts'=> $this->mvts,
      ]
    ]);
    if ($trace->is(['mod'=> $this->mod]))
      $rpicom->showExtractAsYaml(5, 2);
  }
};