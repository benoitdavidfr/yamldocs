<?php
/*PhpDoc:
name: feature.inc.php
title: feature.inc.php - gestion d'un graphe topologique d'inclusions entre objets géographiques
doc: |
  Chaque noeud du graphe correspond à l'ensemble des objets ayant la même géoloc.

  L'index des noeuds contenu dans $all associe un Node à chaque id d'objet
  Chaque noeud définit un id quotient qui est le min. des ids
journal: |
  6/5/2020:
    - chgt de nom de fichier en feature.inc.php et de nom de classe en Feature
  2-5/5/2020:
    - création
includes:
classes:
functions:
*/
require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Feature {
  static protected $all; // [id => Feature]
  static protected $tracedIds = [];
    //'89344@1943','89344@1972-12-01','89344@1977-01-01','89344@1999-01-01',
    //'89325@1943','89220@1943','89254@1943','89352@1943','89389@1943','89389@1977-01-01']; //[];
  static protected $alertIds = []; // ['89325@1972-12-01'];
  protected $ids; // liste triée des ids des objets correspondant au Feature
  protected $contains=[]; // liste des ids quotient strict. topologiquement inclus dans le feature sous la forme [id => 1]
  protected $within=[]; // liste des ids quotient contenants topologiquement strict. le feature sous la forme [id => 1]
  
  static function get(string $id): ?Feature { return self::$all[$id] ?? null; }
  
  static function goc(string $id): Feature { return self::$all[$id] ?? new self($id); } // get or create
  
  static function all(): array { return self::$all; }
  
  static function allAsArray(): array {
    $array = [];
    foreach (self::$all as $id => $node) {
      if ($node->qid() == $id)
        $array[$id] = $node->asArray();
      else
        $array[$id] = '-> '.$node->qid();
    }
    return $array;
  }
  
  static function showStats(): void {
    echo count(self::$all)," id dans l'index\n";
    $nbreNoeudsDistincts = 0;
    $nbWithin = 0;
    foreach (self::$all as $id => $node) {
      if ($node->qid() == $id) {
        $nbreNoeudsDistincts++;
        $nbWithin += count($node->within);
      }
    }
    echo "$nbreNoeudsDistincts noeuds distincts\n";
    echo "$nbWithin prédicats within\n";
  }
  
  static function show() {
    echo "<b>show()</b>\n";
    $array = [];
    foreach (self::$tracedIds as $id) {
      $node = self::get($id);
      if ($node->qid() == $id)
        $array[$id] = $node->asArray();
      else
        $array[$id] = '-> '.$node->qid();
    }
    echo Yaml::dump($array);
    die("Arrêt ligne ".__LINE__);
  }
  
  function __construct(string $id) {
    if (isset(self::$all[$id]))
      throw new Exception("Erreur d'écrasement sur $id dans Feature");
    if (in_array($id, self::$alertIds))
      throw new Exception("Alerte création de $id interdite dans Feature");
    $this->ids = [$id];
    $this->contains = [];
    $this->within = [];
    self::$all[$id] = $this;
  }
  
  function qid(): string { return $this->ids[0]; }
  
  function asArray(): array {
    return [
      'ids' => $this->ids,
      'contains' => array_keys($this->contains),
      'within' => array_keys($this->within),
    ];
  }

  private function normalize(): void { // assure que contains et within contiennent uniq. des quotients
    $contains = [];
    foreach ($this->contains as $cid => $one)
      $contains[Feature::get($cid)->qid()] = 1;
    $this->contains = $contains;
    $within = [];
    foreach ($this->within as $cid => $one)
      $within[Feature::get($cid)->qid()] = 1;
    $this->within = $within;
  }
  
  function equals(string $eqId): void { // affirme que eqId a même géométrie que $this, eqId peut on non être déjà un Feature
    //89344@1977-01-01->equals(89344@1972-12-01)
    //if (($eqId == '89344@1972-12-01') && in_array('89344@1977-01-01', $this->ids))
      //throw new Exception("Alerte sur '".$this->ids[0]." equals $eqId' interdit dans Feature");
    
    if (in_array($this->ids[0], self::$tracedIds) || in_array($eqId, self::$tracedIds)) {
      echo $this->ids[0],"->equals($eqId)\n";
    }
    if (in_array($eqId, self::$alertIds))
      throw new Exception("Alerte sur '".$this->ids[0]." equals $eqId' interdit dans Feature");
    if ($eq = self::$all[$eqId] ?? null) {
      foreach ($eq->ids as $id) {
        self::$all[$id] = $this;
        $this->ids[] = $id;
      }
      sort($this->ids);
      $this->contains = array_merge($this->contains, $eq->contains);
      $this->within = array_merge($this->within, $eq->within);
      foreach ($this->contains as $cid => $one) {
        self::$all[$cid]->normalize();
      }
      foreach ($this->within as $cid => $one) {
        self::$all[$cid]->normalize();
      }
    }
    else {
      self::$all[$eqId] = $this;
      $this->ids[] = $eqId;
      sort($this->ids);
    }
  }
  
  function contains(string $cid): void { // affirme que $this contient id, id peut déjà être un noeud
    if (in_array($this->ids[0], self::$tracedIds) || in_array($cid, self::$tracedIds)) {
      echo $this->ids[0],"->contains($cid)\n";
    }
    if (in_array($cid, $this->ids))
      throw new Exception("Erreur de boucle contains sur $cid");
    if (!($c = self::$all[$cid] ?? null)) {
      $c = new Feature($cid);
      self::$all[$cid] = $c;
    }
    $this->contains[$cid] = 1;
    $c->within[$this->ids[0]] = 1;
  }
  
  function within(string $cid): void { // affirme que $this est dans cid, cid peut déjà être un noeud
    if (in_array($this->ids[0], self::$tracedIds) || in_array($cid, self::$tracedIds)) {
      echo $this->ids[0],"->within($cid)\n";
    }
    if (in_array($cid, $this->ids))
      throw new Exception("Erreur de boucle within sur $cid");
    if (!($c = self::$all[$cid] ?? null)) {
      $c = new Feature($cid);
      self::$all[$cid] = $c;
    }
    $this->within[$cid] = 1;
    $c->contains[$this->ids[0]] = 1;
  }

  function ids(): array { return $this->ids; } // retourne la liste des ids d'objets ayant même géoloc que l'objet

  function contained(): array { // retourne la liste des noeuds inclus
    $result = [];
    foreach (array_keys($this->contains) as $id)
      $result[] = self::$all[$id];
    return $result;
  }

  function containing(): array { // retourne la liste des noeuds contenants
    $result = [];
    foreach (array_keys($this->within) as $id)
      $result[] = self::$all[$id];
    return $result;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>graph</title></head><body><pre>\n";
if (0) {
  new Feature('A1');
  Feature::get('A1')->equals("A2");
  Feature::get('A1')->equals("A0");
  new Feature('B');
  //Feature::get('A1')->equals('B');

  Feature::get('A1')->contains('AA');
  Feature::get('B')->contains('BB');
  Feature::get('B')->within('BB');

  Feature::get('A1')->equals('B');
  //Feature::get('AA')->equals('BB');
}

if (0) {
  new Feature('01132@0');
  Feature::get('01132@0')->equals('69274@1967-12-31');
  Feature::get('69274@1967-12-31')->within('69286@1972-12-15');
}

if (1) {
  Feature::goc('89220@1972-12-01')->within('89344@1972-12-01');
  Feature::goc('89344@1977-01-01')->within('89344@1972-12-01');
  Feature::goc('89220@1972-12-01')->within('89344@1972-12-01');
  Feature::goc('89220@1943')->equals('89220@1972-12-01');
  Feature::goc('89220@1943')->within('89344@1972-12-01');
  Feature::goc('89254@1972-12-01')->within('89344@1972-12-01');
  Feature::goc('89344@1977-01-01')->within('89344@1972-12-01');
  Feature::goc('89254@1972-12-01')->within('89344@1972-12-01');
  Feature::goc('89254@1943')->equals('89254@1972-12-01');
  Feature::goc('89254@1943')->within('89344@1972-12-01');
  Feature::goc('89325@1977-01-01')->within('89344@1977-01-01');
  Feature::goc('89344@1999-01-01')->within('89344@1977-01-01');
  Feature::goc('89325@1977-01-01')->within('89344@1977-01-01');
  Feature::goc('89325@1977-01-01')->within('89344@1972-12-01');
  Feature::goc('89344@1977-01-01')->equals('89344@1972-12-01');
  Feature::goc('89325@1977-01-01')->equals('89325@1943');
  Feature::goc('89325@1943')->within('89344@1972-12-01');
  Feature::goc('89344@1943')->within('89344@1972-12-01');
  Feature::goc('89352@1972-12-01')->within('89344@1972-12-01');
  Feature::goc('89344@1972-12-01')->within('89344@1972-12-01');
  Feature::goc('89352@1972-12-01')->within('89344@1972-12-01');
  Feature::goc('89352@1943')->equals('89352@1972-12-01');
  Feature::goc('89352@1943')->within('89344@1972-12-01');
  Feature::goc('89389@1999-01-01')->within('89344@1999-01-01');
  Feature::goc('89389@1977-01-01')->within('89344@1977-01-01');
  Feature::goc('89389@1977-01-01')->equals('89389@1999-01-01');
  Feature::goc('89389@1977-01-01')->within('89344@1972-12-01');
  Feature::goc('89344@1972-12-01')->equals('89344@1972-12-01');
  Feature::goc('89389@1977-01-01')->equals('89389@1943');
  Feature::goc('89389@1943')->within('89344@1972-12-01');
  
}
//echo 'all='; print_r(Feature::all());
echo Yaml::dump(Feature::allAsArray());


die("Fin ligne ".__LINE__);

