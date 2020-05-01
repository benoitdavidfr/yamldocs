<?php
/*PhpDoc:
name: rect.inc.php
title: rect.inc.php.php - définition de la classe Rect
classes:
doc: |
journal: |
  1/5/2020:
    - amélioration de Rect::geomBbox()
*/

/*PhpDoc: classes
name: Interval
title: class Interval - intervalle semi-ouvert défini par 2 nombres
doc: |
  Permet de simplifier la méthode Rect::intersects()
  La constante EPSILON est utilisée pour comparer 2 nombres et éviter un résultat arbitraire.
*/
class Interval {
  const EPSILON = 1.0e-5; // de l'ordre de 1 m
  private $min;
  private $max;
  
  function __construct(float $min, float $max) {
    $this->min = $min;
    $this->max = $max;
  }
  
  function __toString() { return "[".$this->min." .. ".$this->max."]"; }
  
  // intersections entre 2 intervalles, utilisation de EPSILON
  function intersects(Interval $i2): bool {
    return (!(($this->max - $i2->min <= self::EPSILON) || ($i2->max - $this->min <= self::EPSILON)));
  }
};

if (0) { // tests classe Interval
  $int1 = new Interval(0, 2);
  echo "int1=$int1<br>\n";
  foreach([
    "2 avant 1"=> new Interval(-2, -1),
    "2 avant int 1"=> new Interval(-1, 1),
    "2 dans 1"=> new Interval(1, 1.5),
    "2 après int 1"=> new Interval(1, 3),
    "2 après 1"=> new Interval(3, 4),
    "2 avant touchant 1"=> new Interval(-1, 0),
  ] as $label => $int2) {
    echo "$label<br>\n";
    echo "$int1 ->intersects($int2) = ",$int1->intersects($int2) ? 'Y': 'N',", ",
         "$int2 ->intersects($int1) = ",$int2->intersects($int1) ? 'Y': 'N',"\n";
  }
  die("FIN tests classe Interval\n");
}

/*PhpDoc: classes
name: Rect
title: class Rect - Gestion de rectangles en coord. géo. lat,lng, semi-ouverts cad que 2 rect qui se touchent ne s'intersectent pas
doc: |
  Attention les Rect sont en (lat,lng)
  et les points en (lng,lat) lorsqu'ils proviennent d'une géométrie GeoJSON
*/
class Rect {
  private $sw; // couple de coord. (lat,lng) SW
  private $ne; // couple de coord. (lat,lng) NE
  
  function __construct(array $coords) { // construction à partir d'un array [latmin, lngmin, latmax, lngmax]
    if (!is_numeric($coords[0]))
      throw new Exception("Erreur dans le paramètre de Rect::__construct(".json_encode($coords).")");
    $this->sw = [$coords[0], $coords[1]];
    $this->ne = [$coords[2], $coords[3]];
  }
  
  function sw(): array { return $this->sw; } // couple de coord. (lat,lng) SW
  function ne(): array { return $this->ne; } // couple de coord. (lat,lng) NE
  
  function __toString() { return '['.$this->sw[0].','.$this->sw[1].','.$this->ne[0].','.$this->ne[1].']'; }
  
  function centre(): array { // centre du rectangle comme couple de coords (lat,lng) 
    return [($this->sw[0]+$this->ne[0])/2, ($this->sw[1]+$this->ne[1])/2];
  }
  
  function area(): float { return ($this->ne[0]-$this->sw[0]) * ($this->ne[1] - $this->sw[1]); }
  
  function qsw(): Rect { // quart SW
    return new Rect([$this->sw[0],$this->sw[1],($this->sw[0]+$this->ne[0])/2,($this->sw[1]+$this->ne[1])/2]);
  }
  function qnw(): Rect { // quart NW
    return new Rect([($this->sw[0]+$this->ne[0])/2,$this->sw[1],$this->ne[0],($this->sw[1]+$this->ne[1])/2]);
  }
  function qse(): Rect { // quart SE
    return new Rect([$this->sw[0],($this->sw[1]+$this->ne[1])/2,($this->sw[0]+$this->ne[0])/2,$this->ne[1]]);
  }
  function qne(): Rect { // quart NE
    return new Rect([($this->sw[0]+$this->ne[0])/2,($this->sw[1]+$this->ne[1])/2,$this->ne[0],$this->ne[1]]);
  }
  function quarters(): array { // décomposition du rectangle en 4 quarts SW/NW/SE/NE
    return [
      $this->qsw(),
      $this->qnw(),
      $this->qse(),
      $this->qne(),
    ];
  }
  
  function geofilterPolygon(): string { // utilisé pour les requêtes ODS
    return sprintf('(%f,%f),(%f,%f),(%f,%f),(%f,%f)',
      $this->sw[0],$this->sw[1],  // SW
      $this->ne[0],$this->sw[1],  // NW
      $this->ne[0],$this->ne[1],  // NE
      $this->sw[0],$this->ne[1]); // SE
  }
  
  // teste si les 4 coords sont identiques
  /*function isEqual(Rect $rect2): bool {
    return (($this->sw[0]==$rect2->sw[0]) && ($this->sw[1]==$rect2->sw[1])
         && ($this->ne[0]==$rect2->ne[0]) && ($this->ne[1]==$rect2->ne[1]));
  }*/
  
  // renvoie vrai ssi les 2 rectangles s'intersectent
  function intersects(Rect $rect2): bool {
    $latInt1 = new Interval($this->sw[0], $this->ne[0]);
    $latInt2 = new Interval($rect2->sw[0], $rect2->ne[0]);
    // Si les 2 intervalles en latitude ne s'intersectent pas alors les rectangles ne s'intersectent pas
    if (!$latInt1->intersects($latInt2)) return false;
    
    $lngInt1 = new Interval($this->sw[1], $this->ne[1]);
    $lngInt2 = new Interval($rect2->sw[1], $rect2->ne[1]);
    // Si les 2 intervalles en longitude ne s'intersectent pas alors les rectangles ne s'intersectent pas
    if (!$lngInt1->intersects($lngInt2)) return false;
    
    return true;
  }
  
  // teste si $this est avant $rect2 dans l'ordre de découpage récursif en 4 quarts
  function isBefore(Rect $rect2): bool {
    $ret = $this->isBefore2($rect2);
    //echo $this,"->isBefore($rect2) -> ",$ret ? 'True' : 'False',"\n";
    return $ret;
  }
  function isBefore2(Rect $rect2): bool {
    // si les 2 rectangles s'intersectent alors le plus grand est avant
    if ($this->intersects($rect2)) {
      //echo "$this intersects $rect2\n";
      return ($this->area() > $rect2->area());
    }
    // sinon d'abord test sur la longitude, celui dont la longitude W est inférieure est avant
    if ($this->sw[1] < $rect2->sw[1]) {
      //echo "lng < \n";
      return true;
    }
    if ($this->sw[1] > $rect2->sw[1]) {
      //echo "lng > \n";
      return false;
    }
    // sinon ensuite test sur la latitude,  celui dont la latitude S  est inférieure est avant
    if ($this->sw[0] < $rect2->sw[0]) {
      //echo "lat < \n";
      return true;
    }
    if ($this->sw[0] > $rect2->sw[0]) {
      //echo "lat > \n";
      return false;
    }
    // cas impossible car les rectangles s'intersectent
    throw new Exception("Erreur dans Rect::isBefore() ligne ".__LINE__);
  }
  
 
  function bound(array $lngLat) { // retourne le rectangle min incluant $this et le point (lng,lat)
    return new Rect([
      min($this->sw[0], $lngLat[1]), min($this->sw[1], $lngLat[0]),
      max($this->ne[0], $lngLat[1]), max($this->ne[1], $lngLat[0])]);
  }
  
  // construit la bbox d'une liste de points GeoJSON donc (lng,lat)
  static function bboxOfListOfGeoJSONPoints(array $coords): Rect {
    $bbox = null;
    foreach ($coords as $lngLat) {
      if (!is_numeric($lngLat[0]))
        throw new Exception("Erreur dans bboxOfListOfGeoJSONPoints() sur lngLat=".json_encode($lngLat));
      $bbox = $bbox ? $bbox->bound($lngLat) : new Rect([$lngLat[1], $lngLat[0], $lngLat[1], $lngLat[0]]);
    }
    return $bbox;
  }
  
  // fabrique le bbox de la géométrie GeoJSON donc (lng,lat)
  static function geomBbox(array $geom): Rect {
    switch ($geom['type']) {
      case 'LineString': {
        return Rect::bboxOfListOfGeoJSONPoints($geom['coordinates']);
      }
      case 'MultiLineString': {
        $bbox = null;
        foreach ($geom['coordinates'] as $ls) {
          $lsBbox = Rect::bboxOfListOfGeoJSONPoints($ls);
          $bbox = $bbox ? $bbox->union($lsBbox) : $lsBbox;
        }
        return $bbox;
      }
      case 'Polygon': {
        return Rect::bboxOfListOfGeoJSONPoints($geom['coordinates'][0]);
      }
      case 'MultiPolygon': {
        $bbox = null;
        foreach ($geom['coordinates'] as $polygon) {
          $polygBbox = Rect::bboxOfListOfGeoJSONPoints($polygon[0]);
          $bbox = $bbox ? $bbox->union($polygBbox) : $polygBbox;
        }
        return $bbox;
      }
      default: {
        throw new Exception("Erreur sur Rect::geomBbox(".json_encode($geom).")");
      }
    }
  }
  
  // teste si le point (lat,lng) est dans le rectangle (semi-ouvert)
  function containsPoint(array $latLng): bool {
    return (($latLng[0] >= $this->sw[0]) && ($latLng[0] < $this->ne[0])
        &&  ($latLng[1] >= $this->sw[1]) && ($latLng[1] < $this->ne[1]));
  }
  
  // Teste si le centre de la géométrie GeoJSON est dans le rectangle
  function containsCentreOf(array $geom): bool {
    $geomBbox = Rect::geomBbox($geom);
    return $this->containsPoint($geomBbox->centre());
  }
  
  // retourne l'union des 2 rectangles
  function union(Rect $rect2=null): Rect {
    if (!$rect2) return $this;
    return new Rect([
      min($this->sw[0], $rect2->sw[0]), min($this->sw[1], $rect2->sw[1]),
      max($this->ne[0], $rect2->ne[0]), max($this->ne[1], $rect2->ne[1]),
    ]);
  }
  
  // retourne le niveau Leaflet correspondant à l'affichage du rectangle
  function level(): int {
    $dll = max(
      $this->ne[0]-$this->sw[0],
      ($this->ne[1]-$this->sw[1])/cos(($this->ne[0]+$this->sw[0])/2/180*pi()));
    if ($dll < 1e-3)
      $level = 18;
    elseif ($dll < 1e-2)
      $level = 17;
    elseif ($dll < 2e-2)
      $level = 16;
    elseif ($dll < 5e-2)
      $level = 15; // ok
    elseif ($dll < 0.1)
      $level = 14;
    elseif ($dll < 0.2)
      $level = 13; // ok
    elseif ($dll < 0.5)
      $level = 11;
    elseif ($dll < 1)
      $level = 10; // ok
    elseif ($dll < 2)
      $level = 9;
    elseif ($dll < 5)
      $level = 8;
    elseif ($dll < 10)
      $level = 6;
    elseif ($dll < 20)
      $level = 5;
    elseif ($dll < 40)
      $level = 4;
    else
      $level = 3;
    //echo "dll=$dll -> level=$level<br>\n";
    return $level;
  }
  
  function view(): string { // variables utilisées dans le code JS pour définir la vue
    return json_encode($this->centre(), true).", ".$this->level();
  }
  
  static function test() { // tests classe Rect
    if (0) { // test intersects
      //$rect1 = new Rect([45, 0, 46, 1]);
      $rect1 = new Rect([44.5125,4.89,45.060625,5.7275]);
      echo "rect1=$rect1\n";
      foreach ([
        //"avant lat"=> [40, 0, 41, 1],
        //"avant lng"=> [45, -2, 46, -1],
        //"intersects"=> [45, -1, 46, 1],
        "xx"=> [44.5125,5.7275,45.060625,6.565],
      ] as $label => $rect2) {
        $rect2 = new Rect($rect2);
        //echo "$label => $rect2 ->isBefore($rect1) -> ", $rect2->isBefore($rect1) ? 'Y' : 'N',", ",
        //   "$rect1 ->isBefore($rect2) -> ", $rect1->isBefore($rect2) ? 'Y' : 'N',"\n";
        echo "$label => $rect1 ->intersects($rect2) -> ", $rect1->intersects($rect2) ? 'Y' : 'N', ", ",
              $rect1->intersects($rect2) ? 'Y' : 'N', "\n";
      }
    }
    elseif (0) { // test geomBbox
      $geom = ['type'=> 'LineString', 'coordinates'=>[[0,0],[1,1],[2,2]]];
      echo "geomBbox(",json_encode($geom),") -> ",Rect::geomBbox($geom),"\n";
    }
    else { // test containsCentreOf
      $geom = ['type'=> 'LineString', 'coordinates'=>[[0,0],[1,1],[2,2]]];
      $rect = new Rect([0,0,1,1]);
      $rect = new Rect([0,0,2,2]);
      $rect = new Rect([1,1,2,2]);
      echo "$rect ->containsCentreOf(",json_encode($geom),") -> ",
            $rect->containsCentreOf($geom) ? 'true' : 'false',"\n";
    }
  }
};

if (0) { // tests classe Rect
  Rect::test();
  die("FIN tests classe Rect\n");
}
