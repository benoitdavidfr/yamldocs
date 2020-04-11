<?php
/*PhpDoc:
name: base.inc.php
title: base.inc.php - Gestion d'une base en mémoire + Gestion de critères utile pour gérer la trace
doc: |
  La classe Base gère une base d'objets en mémoire enregistrée en pser.
  La classe Criteria gère des critères utilisés pour gérer la trace dans la classe Base.
journal: |
  11/4/2020:
    - créé par extraction de index.php
classes:
*/
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

{/*PhpDoc: classes
name: Criteria
title: class Criteria - Enregistre des critères et les teste
doc: |
  - la méthode __construct() initialise les critères définis sous la forme:
      [({var} => [{val}] | {var} => ['not'=>[{val}]] | 'not')]
    qui s'interprètent par:
      - si [] alors est toujours vrai
      - si [ 'not' ] alors est toujours faux
      - si [ {var} => [{val}] ] alors la valeur d'une variable {var} doit être parmi les valeurs [{val}]
      - si [ {var} => ['not'=> [{val}]] ] alors, à l'inverse, la valeur de {var} ne doit pas être parmi les valeurs [{val}]
  - la méthode is() teste si les critères sont vérifiés pour un ensemble de variables prenant chacune une valeur défini
    dans le paramètre $params.
    Le résultat est vrai ssi pour chaque variable {var} à la fois dans $params et dans $criteria le critère correspondant 
    est respecté, c'est à dire:
      - s'il est de la forme [{val}] et que la valeur de la variable {var} appartient à cet ensemble
      - sinon s'il est de la forme ['not'=>[{val}]] et que la valeur de la variable {var} n'appartient pas à cet ensemble
    Si $criteria vaut [] alors is() retourne vrai
    Si $criteria vaut ['not'] alors is() retourne faux
  - la méthode statique test() teste la classe
*/}
class Criteria {
  protected $criteria; // critères sous la forme [({var} => [{val}] | {var} => ['not'=>[{val}]] | 'not')]
  
  function __construct(array $criteria) { $this->criteria = $criteria; }
  
  // teste si les critères sont vérifiés pour un ensemble de variables prenant chacune une valeur
  function is(array $params): bool {
    if ($this->criteria == ['not'])
      return false;
    foreach ($this->criteria as $var => $criterium) {
      if (isset($criterium['not'])) {
        if (isset($params[$var]) && in_array($params[$var], $criterium['not']))
          return false;
      }
      else {
        if (isset($params[$var]) && !in_array($params[$var], $criterium))
          return false;
      }
    }
    return true;
  }
  
  // Test de la classe
  static function test() {
    if (0) {
      $trace = new self(['var'=>['Oui']]);
      //$trace = new self(['var'=>['not'=> ['Oui']]]);
      foreach([
        ['var'=>'Oui'],
        ['var'=>'Non'],
        ['var2'=>'xxx']] as $params)
          echo Yaml::dump($params),"-> ",$trace->is($params) ? 'vrai' : 'faux', "<br>\n";
    }
    if (0) {
      $trace = new self(['mod'=> ['not'=> ['31']]]); // affichage mod <> 31
      foreach([
        ['mod'=>'31'],
        ['mod'=>'XXX'],
        ['var2'=>'xxx']] as $params)
          echo Yaml::dump($params),"-> ",$trace->is($params) ? 'vrai' : 'faux', "<br>\n";
    }
    if (1) {
      $trace = new self([]);
      foreach([
        ['mod'=>'31'],
        ['mod'=>'XXX'],
        ['var2'=>'xxx']] as $params)
          echo Yaml::dump($params),"-> ",$trace->is($params) ? 'vrai' : 'faux', "<br>\n";
    }
    die("Criteria::test()");
  }
};

{/*PhpDoc: classes
name: Base
title: class Base - Gestion d'une base en mémoire
doc: |
  La méthode __construct() initialise la base en mémoire à partir du champ contents d'un fichier Yaml ou pser.
  Les méthodes __set(), __get(), __isset() et __unset() modifie ou utilise la base
    - $base->$key = $record pour mettre à jour l'enregistrement correspondant à la clé $key
    - $base->$key pour consulter l'enregistrement correspondant à la clé $key
    - isset($base->$key) pour tester si la clé $key existe dans la base
    - unset($base->$key) pour effacer l'enregistrement correspondant à la clé $key
  La méthode __toString() retourne la base, y compris ses métadonnées, encodée en JSON
  La méthode save() enregistre la base modifiée dans un fichier pser
  La méthode writeAsYaml() enregistre la base dans un fichier Yaml
  Les méthodes de gestion de la base affichent un message en fonction des critères trace définis par le paramètre $trace
  lors de la création et les variables de trace définies par setTraceVar()
methods:
*/}
class Base {
  protected $filepath; // chemin initial
  protected $base; // [ {key} => {record} ]
  protected $metadata; // [ {key} => {val} ]
  protected $trace; // critères de trace
  protected $traceVars = []; // variables utilisées pour tester si la trace est active ou non
  
  // affectation d'une des variables utilisées pour tester la verbosité
  function setTraceVar(string $var, $val) { $this->traceVars[$var] = $val; }
  
  function __construct(string $fpath='', Criteria $trace=null) {
    {/*PhpDoc: methods
    name: __construct
    title: function __construct(string $fpath='', Criteria $trace=null) - Initialisation de la base
    doc: |
      Si $fpath=='' alors la base est initialisée à vide ;
      Sinon si le fichier $fpath.pser existe et est plus récent qu'un éventuel fichier yaml alors ce fichier pser est utilisé ;
      Sinon si le fichier yaml existe alors il est utilisé et enregistré en pser (pour accélérer une prochaine utilisation) ;
      Sinon la base est initialisée à vide.
      Si $fpath n'est pas vide mais que les fichiers n'existent pas alors ce $fpath sera utilisé lors d'un save().
      La base correspond au champ contents du fichier Yaml/pser ; les autres champs sont considérés comme les métadonnées
      de la base.
    */}
    $this->filepath = $fpath;
    if (!$fpath) {
      $base = ['contents'=> []];
    }
    elseif (is_file("$fpath.pser") && (is_file("$fpath.yaml") && (filemtime("$fpath.pser") > filemtime("$fpath.yaml")))) {
      $base = unserialize(file_get_contents("$fpath.pser"));
    }
    elseif (is_file("$fpath.yaml")) {
      $base = Yaml::parse(file_get_contents("$fpath.yaml"));
      file_put_contents("$fpath.pser", serialize($base));
    }
    else {
      $base = ['contents'=> []];
    }
    $this->base = $base['contents'];
    unset($base['contents']);
    $this->metadata = $base;
    $this->trace = $trace ?? new Criteria([]);
  }
  
  function __set(string $key, $record): void {
    if ($this->trace->is($this->traceVars))
      echo "Base::__set($key, ",json_encode($record, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),")\n";
    $this->base[$key] = $record;
  }
  
  function __get(string $key) {
    if ($this->trace->is($this->traceVars))
      echo "Base::__get($key)\n";
    return $this->base[$key] ?? null;
  }
  
  function __isset(string $key): bool {
    if ($this->trace->is($this->traceVars))
      echo "Base::__isset($key)\n";
    return isset($this->base[$key]);
  }
  
  function __unset(string $key): void {
    if ($this->trace->is($this->traceVars))
      echo "Base::__unset($key)\n";
    unset($this->base[$key]);
  }
  
  function __toString(): string {
    return json_encode(
      array_merge($this->metadata, ['contents'=> $this->base]),
      JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  }
  
  function save(string $filepath='', array $metadata=[]) {
    {/*PhpDoc: methods
    name: save
    title: function save(string $filepath='', array $metadata=[]) - sauve la base en PSER pour une réutilisation 
    doc: |
      Si $metadata non défini alors ceux initiaux sont repris
      Si $filepath est non défini alors
        s'il avait été défini à l'init. alors il est réutilisé
        sinon une Exception est levée
    */}
    if (!$filepath) {
      if (!$this->filepath)
        throw new Exception("Dans Base::save() le paramètre filepath doit être défini s'il ne l'a pas été à l'initialisation");
      $filepath = $this->filepath;
    }
    if (!$metadata)
      $metadata = $this->metadata;
    return file_put_contents("$filepath.pser", serialize(array_merge($metadata, ['contents'=> $this->base])));
  }

  function writeAsYaml(string $filepath='', array $metadata=[]) {
    {/*PhpDoc: methods
    name: writeAsYaml
    title: function writeAsYaml(string $filepath='', array $metadata=[]) - enregistre le contenu de la base dans un fichier Yaml
    doc: |
      Si $metadata non défini alors ceux initiaux sont repris
      Si $filepath est non défini alors
        s'il avait été défini à l'init. alors il est réutilisé
        sinon affichage du Yaml
    */}
    // post-traitement, suppression des communes ayant uniq. un nom comme propriété pour faciliter la visualisation
    ksort($this->base);
    foreach ($this->base as $c => $com) {
      if (isset($com['name']) && (count(array_keys($com))==1))
        unset($this->base[$c]);
    }
    if (!$metadata)
      $metadata = $this->metadata;
    if (!$filepath)
      $filepath = $this->filepath;
    if ($filepath)
      return file_put_contents("$filepath.yaml", Yaml::dump(array_merge($metadata, ['contents'=> $this->base]), 99, 2));
    else
      echo Yaml::dump(array_merge($metadata, ['contents'=> $this->base]), 99, 2);
  }

  static function test() {
    echo '<pre>';
    if (1) {
      //$base = new Base(__DIR__.'/basetest');
      $base = new Base;
      echo '$base='; print_r($base);
      //$base->key = ['valeur test créée le '.date(DATE_ATOM)];
      $base->key = 'valeur test créée le '.date(DATE_ATOM)  ;
      //$base->writeAsYaml(__DIR__.'/basetest', ['title'=> "Base test"]);
      $base->writeAsYaml();
      $base->save(__DIR__.'/basetest', ['title'=> "Base test"]);
      //$base->save();
    }
    if (1) {
      $base = new Base('', new Criteria(['not']));
      $base->key = ['valeur test créée le '.date(DATE_ATOM)];
      $base->writeAsYaml();
      echo "$base\n";
    }
    if (1) {
      $base = new Base;
      $key = 256;
      $base->$key = ['valeur test créée le '.date(DATE_ATOM)];
      $base->writeAsYaml();
    }
  }
};


if (basename(__FILE__)<>basename($_SERVER['PHP_SELF'])) return;


require_once __DIR__.'/../../vendor/autoload.php';

// Tests unitaires des classe Verbose et Base
echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>test Base</title></head><body>\n";

if (0) { Criteria::test(); } // Test de la classe Verbose

Base::test();
die("Fin Base::test()\n");

