<?php
/*PhpDoc:
name: zone.inc.php
title: zone.inc.php - def. la classe Zone
screens:
doc: |


journal:
  25/6/2020:
    - première version
*/

// Structuration en zones. Chaque zone est identifiée par la version d'entité la plus ancienne
class Zone {
  static $all=[]; // [ Zone ] - contient tte les zones
  static $includes=[]; // enregistre les couples  (big inclus small) sous la forme [small => [big]]
  protected $vids; // [string] - liste des id de version ({statut}{cinsee}@{dCreation}) corr. à la zone, triée,
                   // vIds[0] est d'id de la zone
  protected $ref; // le référentiel dans lequel la zone est définie
  protected $parent; // Zone - zone parente ou null
  protected $children; // [Zone] - liste des zones incluses
  
  // crée une zone, l'enregistre et la retourne
  static function create(string $id): Zone {
    $new = new self($id);
    self::$all[] = $new;
    return $new;
  }
  
  // retrouve une zone par son id
  static function get(string $id): ?Zone {
    foreach (self::$all as $zone) {
      if (in_array($id, $zone->vids))
        return $zone;
    }
    return null;
  }
  
  // si la zone existe la retourne, sinon la crée
  static function getOrCreate(string $id): Zone {
    if ($zone = self::get($id))
      return $zone;
    else
      return self::create($id);
  }
  
  // affirme que $bigId inclus $smallId
  static function includes(string $bigId, string $smallId) {
    if (!isset(self::$includes[$smallId]))
      self::$includes[$smallId] = [$bigId];
    else
      self::$includes[$smallId][] = $bigId;
    //self::getOrCreate($bigId)->addChild(self::getOrCreate($smallId));
  }
  
  // affirme que les zones sont identiques
  static function sameAs(string $id1, string $id2): void {
    $z1 = self::get($id1);
    $z2 = self::get($id2);
    if (!$z1 && !$z2)
      $z1 = self::create($id1);
    if ($z1 && !$z2)
      $z1->addId($id2);
    elseif ($z2 && !$z1)
      $z2->addId($id1);
    else { // les 2 objets existent, je les fusionne dans z1
      if ($z1->parent && $z2->parent && ($z1->parent <> $z2->parent)) {
        echo "id1=$id1 ->"; print_r($z1);
        echo "id2=$id2 ->"; print_r($z2);
        throw new Exception("Erreur parents distincts dans fusion");
      }
      if (!$z1->parent)
        $z1->parent = $z2->parent;
      $z1->addId($id2);
      foreach ($z2->children as $child)
        $z1->addChild($child);
      self::deleteFromAll($z2);
    }
  }
  
  // retourne l'id identifiant
  static function stdId(string $id): string {
    if (!($z = self::get($id)))
      return $id;
    else
      return $z->id();
  }
  
  static function traiteInclusions(): void {
    //echo Yaml::dump(self::allAsArray());
    
    // standardise les clés de self::$includes
    foreach (array_keys(self::$includes) as $small) {
      $stdId = self::stdId($small);
      if ($stdId <> $small) {
        if (in_array($stdId, array_keys(self::$includes))) {
          self::$includes[$stdId] = array_values(array_unique(array_merge(self::$includes[$stdId], self::$includes[$small])));
          unset(self::$includes[$small]);
        }
        else {
          self::$includes[$stdId] = self::$includes[$small];
          unset(self::$includes[$small]);
        }
      }
    }
    
    // standardise les valeurs de self::$includes
    foreach (self::$includes as $small => $bigs) {
      $stdbigs = [];
      foreach ($bigs as $big) {
        $stdId = self::stdId($big);
        if (!in_array($stdId, $stdbigs))
          $stdbigs[] = $stdId;
      }
      self::$includes[$small] = $stdbigs;
    }
    
    
    foreach (self::$includes as $small => $bigs) {
      if (count($bigs) > 1)
        echo Yaml::dump(['includes'=> [$small => $bigs]]);
    }
    die("Fin Zone::traiteInclusions()\n");
  }
  
  static function deleteFromAll(Zone $zoneToDelete): void {
    foreach (self::$all as $no => $zone)
      if ($zone === $zoneToDelete)
        unset(self::$all[$no]);
  }
  
  // Retourne ttes les zones (structurées hiérarchiquement)
  static function allAsArray(): array {
    $all = [];
    foreach (self::$all as $zone) {
      if (!$zone->parent)
        $all[$zone->id()] = $zone->asArray();
    }
    ksort($all);
    $all['includes'] = self::$includes;
    return $all;
  }
  
  // création à partir d'1 id
  function __construct(string $vid) {
    $this->vids = [ $vid ];
    $this->parent = null;
    $this->children = [];
  }
  
  function id(): string { return $this->vids[0]; }
  
  function asArray(): array {
    $array = ['vids'=> [], 'children'=>[]];
    foreach ($this->vids as $no => $id)
      if ($no)
        $array['vids'][] = $id;
    foreach ($this->children as $child)
      $array['children'][$child->id()] = $child->asArray();
    ksort($array['children']);
    return $array; 
  }
  
  function addChild(Zone $child) {
    $this->children[] = $child;
    $child->parent = $this;
  }
  
  function addId(string $id2) {
    $idDate = substr($this->id(), 7);
    $id2Date = substr($id2, 7);
    if (strcmp($id2Date, $idDate) < 0)
      array_unshift($this->vids, $id2);
    else
      array_push($this->vids, $id2);
  }
};

