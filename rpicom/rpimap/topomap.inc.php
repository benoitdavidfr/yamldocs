<?php
/*PhpDoc:
name: topomap.inc.php
title:  topomap.inc.php - gestion d'une carte topologique
doc: |
  Une carte topologique est un concept destiné à représenter et à gérer un graphe topologique planaire.
  Il se fonde sur le concept de brin qui correspond à une limite commune entre 2 faces du graphe prise dans un sens ou dans l'autre.
  Les relations entre limites, noeuds et faces sont gérés par:
    - la définition de l'inverse des brins (bijection nommée alpha), l'inverse de l'inverse étant le brin lui-même,
    - la définition du brin suivant définissant une face en tournant dans le sens des aiguilles d'une montre
      (conformément à la règle Right-Hand-Rule - the area that is bounded by the polygon is to the right of the boundary),
      formalisée par une bijection nommée fi: Blade -> Blade qui associe à chaque brin le suivant,
    - la définition des anneaux, correspondant aux cycles des brins de fi,
    - la définition de chaque face comme un ensemble d'anneaux,
      - l'un d'entre eux, de surface positive, constitue l'extérieur de la face,
      - les autres, chacun de surface négative, constituent les trous dans la face inclus dans son extérieur.
    Cette structuration des anneaux définit une relation d'inclusion entre les parties connexes de la carte.
    On peut ajouter à la carte une face particulière appelée **son extérieur** qui ne comporte pas elle-même d'extérieur mais uniquement
    comme trous les composantes connexes les plus globales de la carte,
    - la définition des noeuds comme les cycles de la fonction sigma = alpha o fi ; la position d'un noeud est la dernière position
      de chaque brin.

  Ce fichier propose de gérer une carte au moyen des 6 classes définies ci-dessous.
  Les objets des 6 classes sont fortement liés, la création de la carte est effectuée au travers des 3 opérations suivantes :
    - static function Lim::add(array $feature) - Ajoute à la carte une limite comportant un id. droit et évent. un id. gauche
    - function Face::createRings(): bool - Crée les anneaux par détection des cycles de brins pour fi
    - static function Face::createExterior(): void - Crée l'extérieur de la carte et l'enregistre comme Face dans Face::$exterior
  
  Les modifications de la carte peuvent être effectuées au travers des 4 opérations suivantes:
    - static function Blade::concat(int $bnum0, int $next): void - Concatene 2 brins distincts séparés par un noeud ne connectant qu'eux
    - function Face::delete(): bool - supprime une petite face, renvoie false si cas non implémenté
    - function Lim::deleteSmallLim(int $numLim): bool - supprime une petite limite entre 2 noeuds distincts en fusionnant ces 2 noeuds
    - static function Lim::simplify(): void - simplifie les limites et arrondit les coordonnées

  Ces opérations ne permettent pas de contruire la topologie mais uniquement de gérer les objets d'une carte déjà construite.
  
  Une carte peut être stockée sous la forme d'une couche de limites, chacune correspondant à une LineString et définissant une face
  à droite et une autre à gauche, chacune identifiée par un identifiant.
  Si la carte définit des objets surfaciques, elle peut être stockée sous la forme d'une couche de polygones et multi-polygones,
  chacun correspondant à un des objets gérés, par exemple une commune.
  
  Le script mklim.php construit la couche des limites à partir de la couche des polygones et multi-polygones.
  Par convention l'identifiant d'une face est créé en concaténant à l'identifiant d'un polygone la chaine '/u' et à l'identifiant d'un
  multi-polygone la chaine constituée du caractère '/' suivi du numéro de polygone.

  Règles de cohérence d'une carte:
    - l'intersection de 2 faces quelconques est soit vide soit constituée des limites communes,
    - l'intersection de 2 limites quelconques est soit vide soit constituée d'un noeud commun de début ou de fin,
    - chaque face comporte un et un seul anneau extérieur, à l'exception de la face extérieure qui n'en n'a pas,
    - les anneaux extérieurs ont une surface positive, les anneaux intérieurs ont une surface négative,
    - fi définit des cycles correspondant aux anneaux des faces,
    - sigma définit des cycles correspondant aux noeuds.
classes:
*/
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/*PhpDoc: classes
name: class Ring
title:  class Ring - Anneau d'une face défini par un cycle de brins pour fi()
doc: |
  On distingue les anneaux extérieurs des faces, qui ont une surface positive, des trous, qui ont une surface négative.
  Les coordonnées d'un anneau sont définies par la concaténation des coordonnées des brins du cycle correspondant.
*/
class Ring {
  protected $bladeNum; // int - le num. d'un brin représentant le cycle, les autres nuM; sont déduits par Blade::fi()
  
  function __construct(array $bladeNums) {
    $this->bladeNum = $bladeNums[0];
    foreach ($bladeNums as $bladeNum) {
      if (isset($prec))
        Blade::get($prec)->setFiNum($bladeNum);
      $prec = $bladeNum;
    }
    Blade::get($prec)->setFiNum($bladeNums[0]);
  }
  
  function bladeNums(): array { // reconstitue la liste des num. de brins de l'anneau
    //echo "Fabrication du cycle bladeNums\n";
    $bladeNums = [];
    $bladeNum = $this->bladeNum;
    //echo "  bladeNum=$bladeNum\n";
    for ($counter=0; $counter<100000; $counter++) {
      $bladeNums[] = $bladeNum;
      $bladeNum = Blade::get($bladeNum)->fiNum();
      //echo "  bladeNum=$bladeNum\n";
      if ($bladeNum == $this->bladeNum) {
        //echo Yaml::dump(['bladeNums()'=> $bladeNums]);
        return $bladeNums;
      }
    }
    throw new Exception("Nbre d'itérations max atteint pour bladeNum = $this->bladeNum");
  }
  
  function asArray(): array { return $this->bladeNums(); }

  function coords(): array { // LPos
    $coords = [];
    foreach ($this->bladeNums() as $num) {
      if ($coords)
        array_pop($coords);
      $coords = array_merge($coords, Blade::get($num)->coords());
    }
    return $coords;
  }
  
  function replaceBladeNum(int $old, int $new): void { // remplace un no de brin par un autre 
    if ($this->bladeNum == $old)
      $this->bladeNum = $new;
  }
  
  // calcul de surface en coord. géo. avec résultat en ha
  // utilise gegeom\Geometry
  function areaHa(): float {
    $lpos = $this->coords();
    $lineString = gegeom\Geometry::fromGeoJson(['type'=> 'Polygon', 'coordinates'=> [$lpos]]);
    return $lineString->area() // en degrés carrés
      * (40000 * 40000 / 360 / 360) * cos($lpos[0][1]/180*pi()) // en km carrés
      * 100; // en ha
  }

  // traitement des cas simples
  function delete(): bool {
    $bladeNums = $this->bladeNums();
    if (count($bladeNums) == 1) { // cas simple d'une ile en mer ou dans une autre commune
      $num = $this->bladeNum;
      if (Blade::get($num)->left())
        Blade::get($num)->left()->deleteHoleDefinedByOneBlade(-$num);
      Blade::removeBladeFromAll($num);
      return true;
    }
    return false;
    /*if (count($this->bladeNums) == 2) { // cas d'une langue entre 2 communes
      $num1 = $this->bladeNums[0]; // celui que je vais supprimer
      $num2 = $this->bladeNums[1]; // celui qui va rester
      if (Blade::get($num1)->left() == null) {
        $tmp = $num2; $num2 = $num1; $num1 = $tmp;
      }
      Blade::get($num2)->setLeft(Blade::get($num1)->left());
      Blade::removeFromAll($num1);
      return true;
    }
    return false;*/
  }
};

/*PhpDoc: classes
name: class Face
title:  class Face - Polygone défini par des anneaux, 1 pour l'extérieur, les autres sont les trous, sauf la face extérieure qui ne comporte que des trous
doc: |
*/
class Face {
  static $all; // [ id => Face ]
  static $exterior; // Face - la face extérieure qui n'a pas d'extérieur et qui a comme trou chaque composante connexe de la carte
  protected $id;
  protected $bladeNums; // [ int ] puis [] après createRings, la liste des brins affectés à la face lors de la lecture du fichier
  protected $rings; // [ Ring ] après createRings, la face définie comme ensemble d'anneaux
  
  static function get(string $id): ?Face { return self::$all[$id] ?? null; }
  
  static function getAndAddBlade(string $id, int $bladeNum): Face { // Ajout d'un brin à une face
    if (!isset(self::$all[$id]))
      self::$all[$id] = new Face($id);
    self::$all[$id]->bladeNums[] = $bladeNum;
    return self::$all[$id];
  }
  
  function __construct(string $id) { $this->id = $id; $this->bladeNums = []; }
  
  function id(): string { return $this->id; }
  function bladeNums(): array { return $this->bladeNums; }
  function nbRings(): int { return count($this->rings); }
  
  function asArray(): array {
    if ($this->bladeNums) {
      foreach($this->bladeNums as $num)
        $blades[$num] = Blade::get($num)->asArray();
      return $blades;
    }
    else {
      $rings = [];
      foreach($this->rings as $nr => $ring)
        $rings[$nr] = $ring->asArray();
      return $rings;
    }
  }
  
  private function bladeStartingAtPos(array $pos): int { // utilisée par createRings() 
    foreach ($this->bladeNums as $i => $bn) {
      if (Blade::get($bn)->start() == $pos) {
        unset($this->bladeNums[$i]);
        return $bn;
      }
    }
    return 0;
  }
  
  function createRings(): bool { // Création des anneaux en construisant les cycles de brins
    $bladeNums = $this->bladeNums;
    $this->rings = [];
    while ($this->bladeNums) {
      $bn0 = array_pop($this->bladeNums);
      $oneCycle = [ $bn0 ];
      $pos0 = Blade::get($bn0)->start();
      $pos = Blade::get($bn0)->end();
      //echo "démarrage sur bn0=$bn0\n";
      while ($nextBn = $this->bladeStartingAtPos($pos)) {
        //echo "continue sur $nextBn\n";
        $oneCycle[] = $nextBn;
        $pos = Blade::get($nextBn)->end();
      }
      if ($pos <> $pos0) {
        //echo "*** Erreur de createRings() sur id=$this->id avec [$pos[0], $pos[1]] <> [$pos0[0], $pos0[1]]\n";
        $this->bladeNums = $bladeNums;
        return false;
      }
      $this->rings[] = new Ring($oneCycle);
    }
    return true;
  }

  static function createExterior(): void { // crée la face extérieure et l'enregistre dans self::$exterior
    //echo "createExterior()\n";
    self::$exterior = new Face('exterior');
    self::$all['exterior'] = self::$exterior;
    // affecte à la face tous les brins ayant un côté à l'exterieur
    foreach (Lim::$all as $num => $lim) {
      if (!$lim->left()) {
        $lim->setLeft(self::$exterior);
        self::$exterior->bladeNums[] = - $num;
      }
    }
    if (!self::$exterior->createRings())
      echo "Erreur de création de l'extérieur\n";
  }
  
  function replaceBladeNum(int $old, int $new): void { // remplace un no de brin utilisé comme représentant du cycle par un autre 
    foreach ($this->rings as $ring)
      $ring->replaceBladeNum($old, $new);
  }
    
  function areaHa(): float { // calcule la surface de la face comme somme des surfaces de ses anneaux
    $area = 0;
    foreach ($this->rings as $ring)
      $area += $ring->areaHa();
    return $area;
  }
  
  function delete(): bool { // supprime la face, renvoie false si pas possible, cad non implémenté
    if (count($this->rings) == 1) {
      if ($this->rings[0]->delete()) {
        unset(Face::$all[$this->id]);
        return true;
      }
    }
    return false;
  }
  
  function deleteHoleDefinedByOneBlade(int $bladeNum): void { // supprime un trou dans la face défini par un brin
    foreach ($this->rings as $numRing => $ring) {
      if ($ring->bladeNums() == [$bladeNum]) {
        unset($this->rings[$numRing]);
        $this->rings = array_values($this->rings);
        return;
      }
    }
    throw new Exception("Face::deleteHoleDefinedByOneBlade() non effectué");
  }

  function coords(): array { // coordonnées du Polygon - LLPos
    if (count($this->rings) == 1) {
      return [ $this->rings[0]->coords() ];
    }
    else {
      $llpos = [];
      foreach ($this->rings as $ring) {
        if ($ring->areaHa() > 0) {
          $llpos = [ $ring->coords() ];
          $exterior = $ring;
          break;
        }
      }
      foreach ($this->rings as $ring) {
        if ($ring <> $exterior)
          $llpos[] = $ring->coords();
      }
      return $llpos;
    }
  }
};

/*PhpDoc: classes
name: class Node
title:  class Node - Noeud défini comme cycle de sigma
doc: |
*/
class Node { // Méthode sur les noeuds définis comme cycle de sigma
  protected $bladeNum; // int - le num. d'un brin représentant le cycle, les autres num sont déduits par Blade::sigma()
  
  static function check(int $bnum0): void { // S'assure de la validité du noeud après modifications
    $bnum = $bnum0;
    $pos = Blade::get($bnum0)->end();
    for ($counter=0; $counter < 1000; $counter++) {
      $bnum = Blade::get($bnum)->sigmaNum();
      if ($bnum == $bnum0)
        return;
      //echo "  sigma: $bnum\n";
      if (Blade::get($bnum0)->end() <> $pos)
        throw new Exception("Erreur de position sur le noeud défini par $bnum0 sur le brin $bnum");
    }
    throw new Exception("Erreur de boucle illimitée dans Node::checkNode($bnum0)");
  } 
  
  function __construct(int $bladeNum) { $this->bladeNum = $bladeNum; }
  
  function bladeNums(): array {
    $bnum = $this->bladeNum;
    $bladeNums = [$bnum];
    for ($counter=0; $counter < 1000; $counter++) {
      $bnum = Blade::get($bnum)->sigmaNum();
      if ($bnum == $this->bladeNum)
        return $bladeNums;
      $bladeNums[] = $bnum;
    }
    throw new Exception("Erreur de boucle illimitée dans Node::checkNode($bnum0)");
  }
  
  function asArray(): array {
    return [
      'bladeNums'=> $this->bladeNums(),
      'pos'=> Blade::get($this->bladeNum)->end(),
    ];
  }
  
  function __toString(): string { return json_encode($this->asArray()); }
  
  function nbLims(Node $node): int { // nbre de limites connectant 2 noeuds
    $nbLims = 0;
    $nodeBladeNums = $node->bladeNums();
    foreach ($this->bladeNums() as $bnum)
      if (in_array(-$bnum, $nodeBladeNums))
        $nbLims++;
    return $nbLims;
  }
};

/*PhpDoc: classes
name: abstract class Blade
title:  abstract class Blade - Un brin est une limite ou son inverse, porte la définition des fonctions fi() et sigma() et les coords.
doc: |
  Un brin est soit une limite, soit l'inverse d'une limite.
  Chaque brin qui est une limite est identifié par un numéro positif, son inverse est identifié par le numéro opposé.
  Chaque brin définit une face à droite et un autre à gauche.
  Une des faces peut être l'extérieur qui dans certains cas peut être représenté par l'absence de face.
  La topologie de la carte est définie par les 2 bijections:
   - alpha() qui fait correspondre à un brin son inverse
   - fi() qui fait correspondre à un brin le suivant pour définir la face en tournant dans le sens des aiguilles d'une montre
  ainsi que l'inclusion entre parties connexes définie par anneaux des faces
  On définit aussi la bijection sigma = alpha o fi dont les cycles correspondent aux noeuds ;
    la position d'un noeud est la position finale de chacun des brins de son cycle.
*/
abstract class Blade {
  // récupère un brin à partir de son numéro
  static function get(int $num): Blade {
    if ($num > 0) {
      if (isset(Lim::$all[$num]))
        return Lim::$all[$num];
      else
        throw new Exception("Erreur de Blade::get($num)");
    }
    else {
      if (isset(Lim::$all[-$num]))
        return Lim::$all[-$num]->inv();
      else
        throw new Exception("Erreur de Blade::get($num)");
    }
  }

  static function removeBladeFromAll(int $num): void { unset(Lim::$all[abs($num)]); } // supprime le brin et son inverse
  
  abstract function right(): ?Face;
  abstract function left(): ?Face;
  abstract function coords(): array;
  abstract function start(): array;
  abstract function setStart(array $pos): void;
  abstract function end(): array;
  abstract function setEnd(array $pos): void;
  abstract function fiNum(): int; // renvoie le numéro du brin suivant dans la définition des faces
  function fi(): ?Blade { return $this->fiNum() ? Blade::get($this->fiNum()) : null; } // renvoie le brin suivant ou null
  abstract function setFiNum(int $num): void;
  
  static function fiInvNum(int $bnum0): int { // calcul de fi-1 sur les numéros de brins
    $bnum = $bnum0;
    $counter = 0;
    while (Blade::get($bnum)->fiNum() <> $bnum0) {
      $bnum = Blade::get($bnum)->fiNum();
      if (++$counter > 100)
        throw new Exception("Boucle détectée dans Blade::fiInvNum()");
    }
    echo "fiInvNum($bnum0) = $bnum\n";
    return $bnum;
  }
  
  // sigma = alpha o fi - les cycles de sigma sont les brins qui arrivent au même noeud
  function sigmaNum(): int { return - $this->fiNum(); }
  function setSigmaNum(int $num) { $this->setFiNum(-$num); }
    
  static function sigmaInvNum(int $bnum0): int { // calcul de sigma-1 sur les numéros de brins
    $bnum = $bnum0;
    $counter = 0;
    while (Blade::get($bnum)->sigmaNum() <> $bnum0) {
      $bnum = Blade::get($bnum)->sigmaNum();
      if (++$counter > 100)
        throw new Exception("Boucle détectée dans Blade::sigmaInvNum()");
    }
    //echo "sigmaInvNum($bnum0) = $bnum\n";
    return $bnum;
  }
  
  function asArray(): array {
    return [
      'right'=> $this->right() ? $this->right()->id() : '',
      'left'=> $this->left() ? $this->left()->id() : '',
      'fiNum'=> $this->fiNum(),
      'fiOfInv'=> $this->fiOfInv(),
      'start'=> $this->start(),
      'end'=> $this->end(),
    ];
  }

  // retourne la liste des segments composant le brin sous la forme d'une liste d'objets Segment
  function lSegs(): array {
    $lSegs = [];
    foreach ($this->coords() as $pos) {
      if (isset($precPos))
        $lSegs[] = new gegeom\Segment($precPos, $pos);
      $precPos = $pos;
    }
    return $lSegs;
  }
  
  // concatene 2 brins distincts séparés par un noeud qui ne connecte qu'eux
  // $bnum0 est le numéro du brin courant conservé, $next est le brin en séquence à supprimer
  static function concat(int $bnum0, int $next): void {
    //echo "Concaténation des brins $bnum0 et $next\n";
    //echo Yaml::dump([$bnum0=> Blade::get($bnum0)->asArray(), $next=> Blade::get($next)->asArray()]);
    $sigmaInvNum = Blade::sigmaInvNum($next); // le no du brin qui pointe par sigma vers next
    //echo "sigmaInvNum = $sigmaInvNum\n";
    Blade::get($sigmaInvNum)->setSigmaNum($bnum0); // modif. du sigma pour enlever next
    //echo "$sigmaInvNum ->setSigmaNum($bnum0)\n";
    Blade::get($bnum0)->setFiNum(Blade::get($next)->fiNum()); // reprise du fi de $next
    //echo "$bnum0 ->setFiNum(",Blade::get($next)->fiNum(),")\n";
    $nextCoords = Blade::get($next)->coords();
    array_shift($nextCoords);
    Blade::get($bnum0)->setCoords(array_merge(Blade::get($bnum0)->coords(), $nextCoords));
    Blade::get($next)->right = null;
    Blade::get($next)->left = null;
    Blade::get($next)->fiNum = 0;
    Blade::get($next)->fiOfInv = 0;
    Blade::get($next)->setCoords([]);
    unset(Lim::$all[abs($next)]);
    // Si le brin supprimé est utilisé dans un anneau alors il doit être remplacé
    Blade::get($bnum0)->right()->replaceBladeNum($next, $bnum0);
    Blade::get($bnum0)->left()->replaceBladeNum(-$next, -$bnum0);
  }
};

/*PhpDoc: classes
name: class Lim extends Blade
title:  class Lim extends Blade - Une limite entre 2 faces ou entre une face et l'extérieur
doc: |
*/
class Lim extends Blade {
  static $all=[]; // [ num => Lim ], stockage des limites, num à partir de 1
  protected $statut; // string - statut de la limite
  protected $right; // Face - face à droite
  protected $left; // Face | null - face à gauche, si c'est l'extérieur soit null soit la Face spéciale exterior
  protected $fiNum; // Int - le brin suivant du brin dans l'anneau défini par son numéro
  protected $fiOfInv; // Int - le brin suivant du brin inverse dans l'anneau, défini par son numéro
  protected $coords; // LPos
  
  // méthode utilisée pour construire initialement la carte à partir des limites lues dans le fichier GeoJSON
  static function add(array $feature) {
    if (count($feature['geometry']['coordinates']) < 2) {
      echo Yaml::dump(['$feature'=> $feature]);
      throw new Exception("Erreur d'ajout d'une limite ayant moins de 2 points");
    }
    $noLim = count(self::$all) + 1;
    $right = Face::getAndAddBlade($feature['properties']['right'], $noLim);
    if ($feature['properties']['left'])
      $left = Face::getAndAddBlade($feature['properties']['left'], -$noLim);
    else
      $left = null;
    self::$all[$noLim] = new Lim($feature['properties']['statut'], $right, $left, $feature['geometry']['coordinates']);
  }
  
  // convertit les références aux faces en identifiant pour préparer la serialization
  /*static function deref(): array {
    $lims = [];
    foreach (self::$all as $num => $lim) {
      $lims[$num] = [
        'right'=> $lim->right->id(),
        'left'=> $lim->left ? $lim->left->id() : '',
        'coords'=> $lim->coords,
      ];
    }
    return $lims;
  }*/
  
  // reconvertit les id des faces en références après la déserialization
  /*static function reref(array $lims) {
    foreach ($lims as $num => $lim) {
      self::$all[$num] = new self(
        Face::get($lim['right']),
        $lim['left'] ? Face::get($lim['left']) : null,
        $lim['coords']
      );
    }
  }*/
  
  function __construct(string $statut, Face $right, ?Face $left, array $coords) {
    $this->statut = $statut;
    $this->right = $right;
    $this->left = $left;
    $this->coords = $coords;
    $this->fiNum = 0;
    $this->fiOfInv = 0;
  }
  
  function right(): Face { return $this->right; }
  function setRight(?Face $face): void { $this->right = $face; }
  function left(): ?Face { return $this->left; }
  function setLeft(?Face $face): void { $this->left = $face; }
  function coords(): array { return $this->coords; }
  function setCoords(array $coords): void { $this->coords = $coords; }
  function start(): array { return $this->coords[0]; }
  function setStart(array $pos): void { $this->coords[0] = $pos; }
  function end(): array { return $this->coords[count($this->coords)-1]; }
  function setEnd(array $pos): void { $this->coords[count($this->coords)-1] = $pos; }
  function inv(): Inv { return new Inv($this); }
  function fiNum(): int { return $this->fiNum; }
  function setFiNum(int $num): void { $this->fiNum = $num; }
  function fiOfInv(): int { return $this->fiOfInv; }
  function setFiOfInv(int $num): void { $this->fiOfInv = $num; }
  
  static function stats() {
    echo count(self::$all)," limites\n";
    $nbpos = 0;
    foreach (self::$all as $lim) {
      $nbpos += count($lim->coords);
    }
    echo "$nbpos positions\n";
    echo count(Face::$all)," faces\n";
    $nbRings = 0;
    foreach (Face::$all as $id => $face)
      if ($id <> 'exterior')
        $nbRings += $face->nbRings();
    echo "$nbRings anneaux hors extérieur\n";
  }
  
  static function segLengthKm($pos0, $pos1): float { // longueur d'un segment en km
    $dlon = ($pos1[0] - $pos0[0]) * 40000/360 * cos($pos0[1]/180*pi());
    $dlat = ($pos1[1] - $pos0[1]) * 40000/360;
    //echo "dlon=$dlon, dlat=$dlat, length=",sqrt($dlon*$dlon + $dlat*$dlat),"\n";
    return sqrt($dlon*$dlon + $dlat*$dlat);
  }
  
  function lengthKm(): float { // longueur de la limite en km
    $length = 0;
    foreach ($this->coords as $pos) {
      if (isset($precpos))
        $length += self::segLengthKm($precpos, $pos);
      $precpos = $pos;
    }
    return $length;
  }
  
  // parcourt les brins du noeud et modifie leur position finale
  function modifiePositionFinaleDesBrinsDuNoeud(int $bnum0, array $pos): void {
    $bnum = $bnum0;
    for($counter=0; $counter < 1000; $counter++) {
      $bnum = Blade::get($bnum)->sigmaNum();
      if ($bnum == $bnum0)
        return;
      //echo "  sigma: $bnum\n";
      Blade::get($bnum)->setEnd($pos);
    }
    throw new Exception("Erreur de boucle illimitée dans Lim::parcoursNoeud($bnum0)");
  }
  
  function deleteSmallLim(int $numLim): bool { // supprime une petite limite entre 2 noeuds distincts en fusionnant ces 2 noeuds
    echo "Lim->deleteSmallLim($numLim)\n";
    $endNode = new Node($numLim);
    $startNode = new Node(-$numLim);
    echo "Fusion des noeuds $endNode et $startNode\n";
    
    if ($this->right === $this->left) {
      //throw new Exception("Cas particulier d'isthme non traité dans Lim::deleteSmallLim($numLim)");
      echo ("Cas particulier d'isthme non traité dans Lim::deleteSmallLim($numLim)\n");
      return false;
    }
    
    if ($startNode->nbLims($endNode) > 1) {
      //throw new Exception("Cas particulier de petit polygone non traité dans Lim::deleteSmallLim($numLim)");
      echo ("Cas particulier de petit polygone non traité dans Lim::deleteSmallLim($numLim)\n");
      return false;
    }
    
    if (($this->right->id() == '33103/u') || ($this->left->id() == '33103/u')) {
      echo "Cas particulier de conservation de la petite commune 33103\n";
      return false;
    }
    
    // si l'un des 2 num. de brins est utilisé comme repr. du cycle, utiliser le suivant
    $this->right->replaceBladeNum($numLim, Blade::get($numLim)->fiNum());
    $this->left->replaceBladeNum(-$numLim, Blade::get(-$numLim)->fiNum());
      
    $startPos = $this->start();
    $endPos = $this->end();
    $middle = [($startPos[0]+$endPos[0])/2, ($startPos[1]+$endPos[1])/2];
    // change la géométrie des brins se terminant sur le noeud initial ou le noeud final de la limite détruite
    $this->modifiePositionFinaleDesBrinsDuNoeud($numLim, $middle);
    $this->modifiePositionFinaleDesBrinsDuNoeud(-$numLim, $middle);
    // Je retire le 2 brins correspondant à la limite de leur cycle de sigma et fusionne ces 2 cycles
    $sigmaInvNum = Blade::sigmaInvNum($numLim);
    Blade::get($sigmaInvNum)->setSigmaNum(Blade::get(-$numLim)->sigmaNum());
    echo "  sigmaNum($sigmaInvNum) <- ",Blade::get(-$numLim)->sigmaNum(),"\n";
    $sigmaInvNum = Blade::sigmaInvNum(-$numLim);
    Blade::get($sigmaInvNum)->setSigmaNum(Blade::get($numLim)->sigmaNum());
    echo "  sigmaNum($sigmaInvNum) <- ",Blade::get($numLim)->sigmaNum(),"\n";
      
    // effacement de l'objet pour faciliter la détection d'erreurs ainsi que la récupération de mémoire
    $this->right = null;
    $this->left = null;
    $this->fiNum = 0;
    $this->fiOfInv = 0;
    $this->coords = [];
    unset(self::$all[$numLim]);
    
    Node::check($sigmaInvNum);
    $newNode = new Node($sigmaInvNum);
    echo " -> donne le noeud $newNode\n";
    return true;
  }

  // simplifie les limites et arroundit les coordonnées
  static function simplify(): void {
    foreach (self::$all as $num => $lim) {
      $lpos = [];
      $precPos = null;
      foreach (gegeom\LPos::simplify($lim->coords, 0.005) as $pos) {
        $pos = [round($pos[0], 3), round($pos[1], 3)];
        if ($pos <> $precPos)
          $lpos[] = $pos;
        $precPos = $pos;
      }
      if (count($lpos) == 1) {
        printf("Erreur dans simplify(), la limite {right: %s, left: %s, length: %.3f km} ne conserve qu'un seul point\n",
          $lim->right()->id(), $lim->left() ? $lim->left()->id() : "''", $lim->lengthKm());
        echo '  ',Yaml::dump(['coords' => $lim->coords], 0),"\n";
        echo '  ',Yaml::dump(['simplify' => gegeom\LPos::simplify($lim->coords, 0.002)], 0),"\n";
        echo '  ',Yaml::dump(['simpl+round' => $lpos], 0),"\n";
      }
      $lim->coords = $lpos;
    }
  }
  
  function geojson(): array {
    if (count($this->coords) == 1)
      throw new Exception("Erreur dans Lim::geojson(), la limite ne comporte qu'un seul point");
    return [
      'type'=> 'Feature',
      'properties'=> [
        'statut'=> $this->statut,
        'right'=> $this->right ? $this->right->id() : '',
        'left'=> $this->left ? $this->left->id() : '',
      ],
      'geometry'=> [
        'type'=> 'LineString',
        'coordinates'=> $this->coords,
      ],
    ];
  }
};

/*PhpDoc: classes
name: class Inv extends Blade
title:  class Inv extends Blade - Une limite inverse, cad la limite parcourue géométriquement à l'envers
doc: |
*/
class Inv extends Blade {
  protected $inv; // Lim
  
  function __construct(Lim $inv) { $this->inv = $inv; }
  function right(): ?Face { return $this->inv->left(); }
  function left(): ?Face { return $this->inv->right(); }
  function setLeft(?Face $left): void { $this->inv->setRight($left); }
  function coords(): array { return array_reverse($this->inv->coords()); }
  function setCoords(array $coords): void { $this->inv->setCoords(array_reverse($coords)); }
  function start(): array { return $this->inv->end(); }
  function setStart(array $pos): void { $this->inv->setEnd($pos); }
  function end(): array { return $this->inv->start(); }
  function setEnd(array $pos): void { $this->inv->setStart($pos); }
  function inv(): Blade { return $this->inv; }
  function fiNum(): int { return $this->inv->fiOfInv(); }
  function setFiNum(int $num): void { $this->inv->setFiOfInv($num); }
};
