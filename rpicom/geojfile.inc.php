<?php
/*PhpDoc:
name: geojfile.inc.php
title: geojfile.inc.php - classe GeoJFile implémentant un itérateur de Feature sur fichier GeoJSON
doc: |
  L'idée est de facilter le parcours de fichiers GeoJSON par un générateur Php défini par la classe GeoJFile
  ex de test: php geojfile.inc.php 47.2564 -2.4547 47.2894 -2.4028 < reseau-hta-full.geojson
  L'algo de ce fichier devrait remplacer celui de /geovect/fcoll/fcoll.inc.php
classes:
journal: |
  9/5/2020:
    - optimisation de GeoJFile::quickReadOneFeature()
    - ajout d'un cache pour GeoJFile::quickReadOneFeature()
  25/4/2020:
    - création du fichier par éclatement de select.php pour isoler l'itération des Feature sur un fichier GeoJSON
    - amélioration du code de GeoJFile::readFeatures() pour prendre en compte différents cas de figure
*/
require_once __DIR__.'/../../vendor/autoload.php';
require_once __DIR__.'/rect.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

/*PhpDoc: classes
name:  CacheGeoJFile
title: class CacheGeoJFile - un cache pour GeoJFile::quickReadOneFeature()
methods:
*/
class CacheGeoJFile {
  protected $size; // taille du cache en nbre d'objets
  protected $nbputs=0; // nbre de requêtes put reçues
  protected $nbgets=0; // nbre de requêtes get reçues
  protected $miss=0; // nbre de requêtes get en échec
  protected $cache = []; // [key => object]
  
  function __construct(int $size) { $this->size = $size; }
  
  // ajout d'un objet dans le cache
  function put(string $key, $object): void {
    $this->cache[$key] = $object;
    if (count($this->cache) > $this->size)
      array_shift($this->cache);
    $this->nbputs++;
  }
  
  function get(string $key) {
    $this->nbgets++;
    if (isset($this->cache[$key])) {
      return $this->cache[$key];
    }
    else {
      $this->miss++;
      return null;
    }
  }
  
  function stats(): array {
    return [
      'nbputs'=> $this->nbputs,
      'nbgets'=> $this->nbgets,
      'miss'=> $this->miss,
    ];
  }
};

if (0) { // Test de la classe CacheGeoJFile
  $cache = new CacheGeoJFile(4);
  for($i=0; $i< 30; $i++) {
    $cache->put("objet$i", "objet $i");
    if ($i % 5 == 0)
      $cache->get("objet$i");
    elseif ($i % 5 == 1)
      $cache->get("objet".($i+1));
    print_r($cache);
  }
  echo Yaml::dump(['stats'=> $cache->stats()]);
  die("Fin Test du cache\n");
}

/*PhpDoc: classes
name:  GeoJFile
title: class GeoJFile - classe implémentant un itérateur sur fichier GeoJSON
methods:
*/
class GeoJFile {
  const BUFFLENGTH = 1024 * 1024;
  private $path; // soit URL http soit chemin absolu du fichier
  private $file=null; // le descripteur utilisé pour quickReadOneFeature()
  private $cache=null; // le cache utilisé pour quickReadOneFeature()
  private $encoding; // encodage des caractères du fichier GeoJSON
  private $no; // num. d'objet retourné à partir de 0
  
  /*PhpDoc: methods
  name:  __construct
  title: "function __construct(string $path) - initialisation du GeoJFile déterminé par son chemin absolu"
  */
  function __construct(string $path, string $encoding='UTF-8') {
    if ((strncmp($path, 'http://', 7)<>0) && ($path <> 'php://stdin') && !is_file($path))
      throw new \Exception("Fichier $path inexistant ");
    $this->path = $path;
    $this->encoding = $encoding;
  }
  
  // renvoie des Feature sous la forme d'array
  function readFeatures(): \Generator {
    $file = fopen($this->path, 'r');
    // suppression de l'en-tête du fichier avec itération sur la lecture du fichier
    $found = false;
    $end = '';
    while (($buff = fgets($file, self::BUFFLENGTH)) !== false) {
      $buff = str_replace(["\n",' '], ['',''], $buff);
      $buff = $end . $buff;
      //echo "lecture d'un buffer dans l'en-tête: $buff<br>\n\n";
      $pattern = '!\{"type":"FeatureCollection","features":\[!';
      if (preg_match($pattern, $buff)) {
        $buff = preg_replace($pattern, '', $buff, 1);
        $found = true;
        break;
      }
      $end = $buff;
    }
    // on sort de la boucle avec un buff soit sur FeatureCollection soit sur Feature
    if (!$found)
      throw new Exception("Erreur début non trouvé\n");

    // lecture du fichier et recherche d'un pattern de feature pour en retourner un à chaque appel
    // ce motif fonctionne pour un Feature dont chaque property n'a pas de sous-sous-objet
    $pattern = '!'
      .'^,?'
      .'(\{'
        .'([^\{]*\{[^\{\}]*\})*'
        .'[^\{\}]*'
      .'\})'
      .'!';
    while (1) {
      // je commence par itérer sur les Feature
      while (preg_match($pattern, $buff, $matches)) {
        //print_r($matches);
        yield json_decode($matches[1], true);
        $buff = preg_replace($pattern, '', $buff, 1);
      }
      $end = $buff;
      
      // avant de lire un nouveau paquet dans le fichier
      if (($buff = fgets($file, self::BUFFLENGTH)) === false)
        break;
      $buff = str_replace(["\n",' '], ['',''], $buff);
      $buff = $end . $buff;
      //echo "lecture d'un buffer: $buff<br>\n\n";
    }
    if ($end <> ']}')
      throw new Exception("Erreur FIN buff=$end\n");
  }
  
  /*PhpDoc: methods
  name:  meetCriteria
  title: static function meetCriteria($criteria, $feature) - teste si des critères sont satisfaits par un feature
  */
  static function meetCriteria($criteria, $feature) {
    if (!$criteria)
      return true;
    foreach ($criteria as $k => $bbox) {
      if ($k <> 'bbox')
        throw new Exception("Erreur critère non prévu");
      return $bbox->intersects(Rect::geomBbox($feature['geometry']));
    }
  }
  
  // calcule le rectangle englobant des features du fichier qui satisfont les critères
  function bbox(array $criteria=[]): ?Rect {
    $bbox = null;
    foreach ($this->readFeatures() as $feature) {
      if (Criteria::meetCriteria($criteria, $feature)) {
        $bbox = Rect::geomBbox($feature['geometry'])->union($bbox);
      }
    }
    return $bbox;
  }
  
  /*PhpDoc: methods
  name:  features
  title: "function features(array $criteria): \\Generator - génère les Feature respectant les critères"
  doc: |
  */
  function features(array $criteria=[]): \Generator {
    $key = 0;
    foreach ($this->readFeatures() as $feature) {
      if (self::meetCriteria($criteria, $feature)) {
        //echo "objet lu<br>\n";
        yield $key++ => $feature;
      }
    }
  }

  /*PhpDoc: methods
  name:  quickReadFeatures
  title: "function quickReadFeatures(): \\Generator - Lecture dans le cas de sortie de ogr2ogr où chaque Feature est enregistré sur une ligne du fichier (format GeoJSONSeq)"
  doc: |
    Renvoit en outre la position du feature dans le fichier permettant ainsi d'y accéder par quickReadOneFeature
    Autorise une modification de l'en-tête
    La règle est que l'en-tête se termine par la ligne '"features": ['
  */
  function quickReadFeatures(): \Generator {
    $file = fopen($this->path, 'r');
    while ($buff = rtrim(fgets($file))) {
      if ($buff == '"features": [') break; // lit l'en-tête du fichier
    }
    $ftell = ftell($file);
    while ($buff = rtrim(fgets($file))) {
      if ($buff == ']')
        return;
      if (substr($buff, -1) == ',')
        $buff = substr($buff, 0, -1); // supp de la , en fin de ligne
      if ($this->encoding <> 'UTF-8')
        $buff = mb_convert_encoding ($buff, 'UTF-8', $this->encoding);
      //echo 'buff=',$buff,"\n";
      $feature = json_decode($buff, true);
      if ($feature === null)
        die("Dans GeoJFile::quickReadFeatures() erreur de décodage de $buff");
      //echo 'decoded=',json_encode($decoded),"\n";
      $feature['ftell'] = $ftell;
      $ftell = ftell($file);
      yield $feature;
    }
    fclose($file);
  }
  
  // lit un feature à la position indiquée
  function quickReadOneFeature(int $pos, int $cacheSize=500): array {
    if (!$this->file)
      $this->file = fopen($this->path, 'r');
    if (!$this->cache)
      $this->cache = new CacheGeoJFile($cacheSize);
    if ($result = $this->cache->get("feature$pos"))
      return $result;
    fseek($this->file, $pos);
    $buff = fgets($this->file);
    $buff = rtrim($buff);
    if (substr($buff, -1) == ',')
      $buff = substr($buff, 0, -1); // supp de la , en fin de ligne
    if ($this->encoding <> 'UTF-8')
      $buff = mb_convert_encoding ($buff, 'UTF-8', $this->encoding);
    $feature = json_decode($buff, true);
    if ($feature === null)
      die("Dans GeoJFile::quickReadOneFeature() erreur de décodage de $buff");
    $this->cache->put("feature$pos", $feature);
    return $feature;
  }
  
  function close() { fclose($this->file); } // fermeture du descripteur de fichier utilisé dans quickReadOneFeature()

  function cacheStats(): array { return $this->cache->stats(); }
};


if (basename(__FILE__) <> basename($_SERVER['PHP_SELF'])) return; // Test unitaire 

if ($argc < 5) {
  die("usage: php $argv[0] {latmin} {lngmin} {latmax} {lngmax}\n"
    ."fonctionne comme un pipe\n"
    ."exemple: php $argv[0] 47.2564 -2.4547 47.2894 -2.4028\n");
}
$rect = new Rect([$argv[1], $argv[2], $argv[3], $argv[4]]);

$geojfile = new GeoJFile('php://stdin');
foreach($geojfile->features(['bbox'=> $rect]) as $feature) {
  print_r($feature);
}