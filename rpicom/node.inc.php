<?php
/*PhpDoc:
name: node.inc.php
title: node.inc.php - gestion d'un graphe topologique d'inclusions
doc: |
  graphe d'inclusion entre objets (versions de commune)
  Chaque noeud du graphe correspond à l'ensemble d'objets ayant la même géoloc
  l'index des noeuds contenu dans $all associe un Node à chaque id d'objet
  Chaque noeud définit un id quotient qui est le min. des ids
journal: |
  2/5/2020:
    - création
includes:
classes:
functions:
*/
require_once __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Node {
  static protected $all; // [id => Node]
  protected $ids; // liste triée des ids des objets correspondant au noeud
  protected $contains=[]; // liste des ids quotient géométriquement inclus dans le noeud sous la forme [id => 1]
  protected $within=[]; // liste des ids quotient contenants le noeud sous la forme [id => 1]
    
  static function get(string $id): Node { return $id ? (self::$all[$id] ?? null) : self::$all; }
  
  static function allAsArray() {
    $array = [];
    foreach (self::$all as $id => $node) {
      if ($node->qid() == $id)
        $array[$id] = $node->asArray();
      else
        $array[$id] = '-> '.$node->qid();
    }
    return $array;
  }
  
  static function showStats() {
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
    echo "$nbWithin arêtes within\n";
  }
  
  function __construct(string $id) {
    $this->ids = [$id];
    $this->contains = [];
    $this->within = [];
    self::$all[$id] = $this;
  }
  
  function qid() { return $this->ids[0]; }
  
  function asArray(): array {
    return [
      'ids' => $this->ids,
      'contains' => array_keys($this->contains),
      'within' => array_keys($this->within),
    ];
  }

  function normalize() { // assure que contains et within contiennent uniq. des quotients
    $contains = [];
    foreach ($this->contains as $cid => $one)
      $contains[Node::get($cid)->qid()] = 1;
    $this->contains = $contains;
    $within = [];
    foreach ($this->within as $cid => $one)
      $within[Node::get($cid)->qid()] = 1;
    $this->within = $within;
  }
  
  function equals(string $eqId) { // affirme que eqId a même géométrie que $this, eqId peut on non être déjà un Node
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
  
  function contains(string $cid): bool { // affirme que $this contient id, id peu déjà être un noeud
    if (!($c = self::$all[$cid] ?? null)) {
      $c = new Node($cid);
      self::$all[$cid] = $c;
    }
    $this->contains[$cid] = 1;
    $c->within[$this->ids[0]] = 1;
    return true;
  }
  
  function within(string $cid): bool { // affirme que $this est dans id, id peu déjà être un noeud
    if (!($c = self::$all[$cid] ?? null)) {
      $c = new Node($cid);
      self::$all[$cid] = $c;
    }
    $this->within[$cid] = 1;
    $c->contains[$this->ids[0]] = 1;
    return true;
  }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return;


echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>graph</title></head><body><pre>\n";
new Node('A1');
Node::get('A1')->equals("A2");
Node::get('A1')->equals("A0");
new Node('B');
//Node::get('A1')->equals('B');

Node::get('A1')->contains('AA');
Node::get('B')->contains('BB');
Node::get('B')->within('BB');

Node::get('A1')->equals('B');
//Node::get('AA')->equals('BB');

//echo 'all='; print_r(Node::all());
echo Yaml::dump(Node::allAsArray());

die("Fin ligne ".__LINE__);

