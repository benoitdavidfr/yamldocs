<?php
/*PhpDoc:
name: deduitgref.php
title: deduitgref.php - déduit le géoréférencement des entités
screens:
doc: |
  Soulève plein de questions:
    - comment retrouver les géoréférencements perdus comme 14010 ?
      - en faire un approximatif à partir des chefs-lieux et par polygones de Voronoï ?
    - comment déduire les équivalences de manière simple et efficace à partir des infos Insee ?
    - comment détecter les entités qui ont un ecomp comme géoréf ?
    - comment stocker le résultat ?
    - comment identifier de manière simple les entités ?
      - s17397@1943-01-01 avec s pour c. simple (statut simplifié) puis code Insee puis date de création
      - statuts simplifiés
        'cSimple'=>'s',
        'cAssociée'=>'a',
        'cDéléguée'=>'d',
        'ardtMun'=>'m',

  Exemple de pb. de géoréf.:
    14010:
      '2014-01-07': { fin: '2017-01-01', statut: cAssociée, crat: '14472', nom: Ammeville, evtFin: { seFondDans: 14654 } }
      '1990-02-01': { fin: '2014-01-07', statut: cAssociée, crat: '14697', nom: Ammeville, evtFin: { changeDeRattachementPour: 14472 } }
      '1973-01-01': { fin: '1990-02-01', statut: cAssociée, crat: '14624', nom: Ammeville, evtFin: { changeDeRattachementPour: 14697 } }
      '1943-01-01': { fin: '1973-01-01', statut: cSimple, crat: null, nom: Ammeville, evtFin: { sAssocieA: 14624 } }
    Impossible de trouver le géoréf de cette entité !!!
    Utiliser les triangles de Voronoï ?

  A améliorer:
    17397:
      '1973-01-01': {fin: null, statut: cSimple, crat: null, nom: Saint-Savinien, evtFin: null }
      '1943-01-01': {fin: '1973-01-01', statut: cSimple, crat: null, nom: Saint-Savinien, evtFin: {prendPourAssociées: [17001,17123]}}
    s17397@1943-01-01 correspond à l'ecomp 17397c
    17397c = 17397 - 17001 - 17123

    33055:
      '2019-01-01': { fin: null, statut: cSimple, crat: null, nom: Blaignan-Prignac, evtFin: null, commeDéléguée: { nom: Blaignan } }
      '1943-01-01': { fin: '2019-01-01', statut: cSimple, crat: null, nom: Blaignan, evtFin: { absorbe: [33338], délègueA: [33055] } }
    33338:
      '1943-01-01': { fin: '2019-01-01', statut: cSimple, crat: null, nom: Prignac-en-Médoc, evtFin: { seFondDans: 33055 } }
    s33338@1943-01-01 correspond à l'ecomp 33055c

    Comment détecter ce type de correspondance ? J'ai 414 ecomp

    Idée: générer un graphe d'inclusion géographique entre versions
journal:
  23/6/2020:
    - abandon, restructuration dans bzone.php
  21/6/2020:
    - première version
*/
ini_set('memory_limit', '2G');

require_once __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/../../../../phplib/pgsql.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

if (!isset($_GET['action'])) {
  echo "<a href='?action=showRpicom'>showRpicom</a><br>\n";
  echo "<a href='?action=deduitgref'>affichage de deduitgref</a><br>\n";
  echo "<a href='?action=triParDFin'>tri par date de fin</a><br>\n";
  echo "<a href='?action=triParRef'>tri par référentiel</a><br>\n";
  die();
}

class Rpicom {
  static $all=[];
  protected $versions=[];
  
  static function add(array $tuple) {
    if (!isset(self::$all[$tuple['cinsee']]))
      self::$all[$tuple['cinsee']] = new self($tuple);
    else
      self::$all[$tuple['cinsee']]->addVersion($tuple);
  }
  
  static function allAsArray(): array {
    $all = [];
    ksort(self::$all);
    foreach (self::$all as $cinsee => $rpicom) {
      $all[$cinsee] = $rpicom->asArray();
    }
    return $all;
  }
  
  static function deduitgrefAll(): array {
    ksort(self::$all);
    $deduitgref = [];
    foreach (self::$all as $cinsee => $rpicom) {
      $deduitgref = array_merge($deduitgref, $rpicom->deduitgref($cinsee));
    }
    return $deduitgref;
  }
  
  function __construct(array $tuple) {
    $this->addVersion($tuple);
  }
  
  function versions(): array { return $this->versions; }
  
  function addVersion(array $tuple) {
    if (!isset($this->versions[$tuple['dcreation']])) {
      $this->versions[$tuple['dcreation']] = [
        'fin'=> $tuple['fin'],
        'statut'=> $tuple['statut'],
        'crat'=> $tuple['crat'],
        'nom'=> $tuple['nom'],
        'evtFin'=> $tuple['evtfin'] ? json_decode($tuple['evtfin'], true) : null,
      ];
    }
    elseif ($tuple['statut']=='cDéléguée') {
      $this->versions[$tuple['dcreation']]['commeDéléguée']['nom'] = $tuple['nom'];
    }
    else {
      echo Yaml::dump([$this->versions[$tuple['dcreation']], $tuple]);
      throw new Exception("Existe déjà $tuple[cinsee]");
    }
  }
  
  function addEvtCreation(array $tuple) {
    $this->versions[$tuple['dcreation']]['evtCreation'] = json_decode($tuple['evt'], true);
  }
  
  function asArray(): array {
    krsort($this->versions);
    return $this->versions;
  }
  
  // Renvoie la version de l'entité $cinsee se terminant à $dFin
  static function vcomParFin(string $cinsee, string $dFin): array {
    $rpicom = self::$all[$cinsee];
    foreach ($rpicom->versions as $dCreation => $version) {
      //echo Yaml::dump([$cinsee => [$dCreation => $version]]);
      if ($version['fin'] == $dFin)
        return $version;
    }
    return [];
  }
  
  // Déduit de l'historique
  //   - soit l'égalité du géoréf. d'une ancienne version avec une version plus récente
  //   - soit une majoration du géoréf. d'une ancienne version par une version plus récente
  // Retourne [ ancienne => nouvelle ]
  // PB: pas de raison d'aller chercher un autre COG que 2020 si celui-ci convient !!!
  // ex: s01033@1971-01-01: '@AE2018'
  // -> il faut d'abord essayer '@AE2020' avant les autres années !!!
  function deduitgref(string $cinsee): array {
    static $statuts = [ // recodage des statuts
      'cSimple'=>'s',
      'cAssociée'=>'a',
      'cDéléguée'=>'d',
      'ardtMun'=>'m',
    ];
    // Cas particuliers, absorbe + rétablitCommeSimple
    if ($cinsee == '08119') {
      /*'08119':
          '1986-01-01': { fin: null, statut: cSimple, crat: null, nom: Cheveuges, evtFin: null }
          '1964-10-01': { fin: '1986-01-01', statut: cSimple, crat: null, nom: Cheveuges-Saint-Aignan, evtFin: { rétablitCommeSimple: ['08377'] } }
          '1943-01-01': { fin: '1964-10-01', statut: cSimple, crat: null, nom: Cheveuges, evtFin: { absorbe: ['08377'] } }
      */
      return [
        's08119@1986-01-01'=> '@',
        's08119@1943-01-01'=> 's08119@1986-01-01',
        // la version de 1974 est l'union des versions de 08119 et 08377 de 1986
        's08119@1964-10-01'=> '+ s08119@1986-01-01 s08377@1986-01-01',
      ];
    }

    $georef = [];
    krsort($this->versions);
    //echo Yaml::dump([$cinsee=> $this->versions]);
    //print_r([$cinsee=> $this->versions]);
    $versions = $this->versions;
    $dCreations = array_keys($versions);
    foreach ($dCreations as $noVersion => $dCreation) {
      if (!isset($versions[$dCreation])) continue; // cas d'enchainement
      $version = $versions[$dCreation];
      $statut = $statuts[$version['statut']];
      //echo Yaml::dump(['$statut'=> [$version['statut'] => $statut]]);
      
      // Test du cas d'enchainement fusion/rétablissement, ex 08377
      //echo Yaml::dump([$dCreation=> $version]);
      if (isset($version['evtCreation']) && is_array($version['evtCreation'])) {
        //echo "Ok ligne ",__LINE__,"\n";
        $key0 = array_keys($version['evtCreation'])[0];
        if ($key0 == 'rétablieCommeSimpleDe') {
          //echo "Ok ligne ",__LINE__,"\n";
          if (isset($dCreations[$noVersion+1])) {
            //echo "Ok ligne ",__LINE__,"\n";
            $vprecDCreation = $dCreations[$noVersion+1];
            $vprec = $this->versions[$vprecDCreation];
            if (is_array($vprec['evtFin']) && (array_keys($vprec['evtFin'])[0]=='fusionneDans')) {
              //echo "Ok ligne ",__LINE__,"\n";
              $georef["$statut$cinsee@$vprecDCreation"] = "$statut$cinsee@$dCreation";
              $versions[$vprecDCreation] = null;
              //echo Yaml::dump(['$versions'=> $versions]);
              continue;
            }
          }
        }
      }
      
      if (is_null($version['evtFin'])) {
        if (count($this->versions) > 1) // on ne gère que les codes qui ont plusieurs versions
          $georef["$statut$cinsee@$dCreation"] = '@AE2020'; // signifie que le géoréf est défini dans AE2020
        continue;
      }
      
      // J'essaie de déuire la version courante des plus récentes en fonction de l'evt de fin
      $evtFin = $version['evtFin'];
      if (is_string($evtFin)) {
        switch ($evtFin) {
          case 'Commune associée rétablie comme commune simple':
          case 'Commune déléguée rétablie comme commune simple':
          case 'Absorbe certaines de ses c. rattachées ou certaines de ses c. associées deviennent déléguées': {
            $georef["$statut$cinsee@$dCreation"] = "$statut$cinsee@$version[fin]";
            break;
          }
          case 'Commune rétablissant des c. rattachées ou fusionnées': {
            $georef["$statut$cinsee@$dCreation"] = "< $statut$cinsee@$version[fin]";
            break;
          }
          case 'Commune rattachée devient commune de rattachement':
          case 'Sort du périmètre du Rpicom': {
            $georef["$statut$cinsee@$dCreation"] = '?';
            break;
          }
          default: {
            throw new Exception("evt ".Yaml::Dump($version['evtFin'], 0));
          }
        }
      }
      elseif (is_array($version['evtFin']) && (count($version['evtFin']) == 1)) {
        $key0 = array_keys($evtFin)[0];
        switch ($key0) {
          case 'délègueA': {
            //echo Yaml::dump([$cinsee=> $this->versions, '$dCreation'=> $dCreation]);
            if (in_array($cinsee, $evtFin['délègueA']))
              $georef["$statut$cinsee@$dCreation"] = "d$cinsee@$version[fin]";
            else
              $georef["$statut$cinsee@$dCreation"] = "< $statut$cinsee@$version[fin]";
            break;
          }
          case 'changeDeNomPour':
          case 'devientDéléguéeDe':
          case 'sAssocieA':
          case 'resteAssociéeA':
          case 'resteDéléguéeDe':
          case 'changedAssociéeEnDéléguéeDe': {
            $georef["$statut$cinsee@$dCreation"] = "$statut$cinsee@$version[fin]";
            break;
          }
          case 'absorbe': {
            // si l'absorbée était déléguée ou associée alors le géoref de l'absorbante ne change pas
            $ccAbsorbees = $evtFin[$key0]; // code INSEE des c. absorbées
            //echo Yaml::dump(['$version'=> $version, '$ccAbsorbees'=> $ccAbsorbees]);
            foreach ($ccAbsorbees as $ccAbsorbee) {
              $vcAbsorbee = Rpicom::vcomParFin($ccAbsorbee, $version['fin']); // la version de la c. absorbée se terminant à la date
              $statutsAbsorbees[$vcAbsorbee['statut']] = 1;
            }
            // Si une des c. absorbées était une c. simple alors la nlle version est un majorant de l'ancienne
            if (in_array('cSimple', $statutsAbsorbees))
              $georef["$statut$cinsee@$dCreation"] = "< $statut$cinsee@$version[fin]";
            else // sinon le géoréf est le même
              $georef["$statut$cinsee@$dCreation"] = "$statut$cinsee@$version[fin]";
            break;
          }
          case 'seFondDans':
          case 'fusionneDans': {
            $fusionneDans = $evtFin[$key0];
            if (in_array($version['statut'], ['cDéléguée','cAssociée']))
              $georef["$statut$cinsee@$dCreation"] = "$statut$fusionneDans@$version[fin]";
            else
              $georef["$statut$cinsee@$dCreation"] = "< $statut$fusionneDans@$version[fin]";
            break;
          }
          case 'prendPourAssociées': {
            $georef["$statut$cinsee@$dCreation"] = "< $statut$cinsee@$version[fin]";
            break;
          }
          case 'rétablitCommeSimple':
          case 'reçoitUnePartieDe':
          case 'contribueA':
          case 'seDissoutDans':
          case 'perdRattachementPour':
          case 'changeDeRattachementPour': {
            $georef["$statut$cinsee@$dCreation"] = '?';
            break;
          }
          case 'quitteLeDépartementEtPrendLeCode': {
            $nouvCinsee = $version['evtFin']['quitteLeDépartementEtPrendLeCode'];
            $georef["$statut$cinsee@$dCreation"] = "$statut$nouvCinsee@$version[fin]";
            break;
          }
          default: {
            echo Yaml::dump([$cinsee=> $this->versions, '$dCreation'=> $dCreation]);
            throw new Exception("evt ".Yaml::Dump($version['evtFin'], 0));
          }
        }
      }
      elseif (is_array($version['evtFin']) && (count($version['evtFin']) > 1)) {
        if (array_keys($evtFin) == ['absorbe','prendPourAssociées'])
          $georef["$statut$cinsee@$dCreation"] = "< $statut$cinsee@$version[fin]";
        elseif (array_keys($evtFin) == ['absorbe','délègueA'])
          $georef["$statut$cinsee@$dCreation"] = '?';
        elseif (($evtFin[0] == 'Commune associée rétablie comme commune simple') && (array_keys($evtFin[1]) == ['sAssocieA']))
          $georef["$statut$cinsee@$dCreation"] = '?';
        elseif (($evtFin[0] == 'Commune associée rétablie comme commune simple') && (array_keys($evtFin[1]) == ['prendPourAssociées']))
          $georef["$statut$cinsee@$dCreation"] = '?';
        elseif ((array_keys($evtFin[0]) == ['absorbe']) && (array_keys($evtFin[1]) == ['quitteLeDépartementEtPrendLeCode']))
          $georef["$statut$cinsee@$dCreation"] = '?';
        elseif ((array_keys($evtFin[0]) == ['changeDeNomPour']) && (array_keys($evtFin[1]) == ['reçoitUnePartieDe']))
          $georef["$statut$cinsee@$dCreation"] = '?';
        else
          throw new Exception("evt ".Yaml::Dump($version['evtFin'], 0));
      }
      else
        throw new Exception("evt ".Yaml::Dump($version['evtFin'], 0));
      
      // si le géoréf n'est pas précisément défini par rapport aux elts existants de COG2020, on cherche par rapport aux précédents
      if (in_array(substr($georef["$statut$cinsee@$dCreation"], 0, 1), ['<','?'])) {
        foreach ([2019, 2018, 2017, 2016, 2015, 2014, 2013, 2003] as $year) {
          if (($statut == 's') && (strcmp($version['fin'], "$year-01-01") > 0)) {
            $georef["$statut$cinsee@$dCreation"] = "@AE$year"; // signifie que le géoréf est défini dans AExxxx
            //echo "$statut$cinsee@$dCreation -> @AE$year\n";
            continue 2;
          }
        }
      }
    }
    //echo Yaml::dump([$cinsee=> ['$georef'=> $georef]]);
    return $georef;
  }
};


if (php_sapi_name() <> 'cli')
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>deduitgref</title></head><body><pre>\n";
PgSql::open('host=172.17.0.4 dbname=gis user=docker password=docker');
//$where = "where cinsee like '17%'";
$where = '';
{/*create table eadminv(
  num serial, -- ==>> utile ? potentiellement pour la table dérivée avec géométrie ?
  cinsee char(5) not null, -- code INSEE
  dcreation date not null, -- date de création de la version, 1/1/1943 par défaut
  fin date, -- lendemain du dernier jour, null ssi version encore valide
  statut admin_statut not null,
  crat char(5), -- pour une entité rattachée code INSEE de la c. de rattachement, null ssi cSimple
  nom varchar(256) not null, -- nom en minuscules
  evtFin jsonb, -- évènement(s) de fin, null ssi encore valide, il peut y en avoir plusieurs
  primary key (cinsee, dcreation, statut) -- le statut dans la clé car une c. déléguée et sa rattachante peuvent avoir même code Insee
)*/}
$sql = "select num, cinsee, dcreation, fin, statut, crat, nom, evtFin from eadminv $where";
foreach (PgSql::query($sql) as $tuple) {
  //echo Yaml::dump($tuple);
  Rpicom::add($tuple);
}

{/*create table evtCreation(
  cinsee char(5) not null, -- code INSEE
  dcreation date not null, -- date de l'évènement
  evt jsonb not null, -- l'évènement
  primary key (cinsee, dcreation)
)*/}
$sql = "select cinsee, dcreation, evt from evtCreation $where";
foreach (PgSql::query($sql) as $tuple) {
  //echo Yaml::dump($tuple);
  Rpicom::$all[$tuple['cinsee']]->addEvtCreation($tuple);
}

if ($_GET['action']=='showRpicom') {
  echo Yaml::dump(Rpicom::allAsArray());
  die();
}

$georefs = Rpicom::deduitgrefAll();
if ($_GET['action']=='deduitgref') {
  echo Yaml::dump($georefs);
  die();
}

if ($_GET['action']=='triParDFin') {
  $sortByDFin = [];
  $nbre = 0;
  foreach ($georefs as $ancV => $def) {
    if (in_array(substr($def, 0, 1), ['?','<'])) {
      if (!preg_match('!^([sadm])(\d[\dAB]\d\d\d)@(\d\d\d\d-\d\d-\d\d)$!', $ancV, $matches))
        throw new Exception("No match on $ancV");
      $v = Rpicom::$all[$matches[2]]->versions()[$matches[3]];
      $sortByDFin[$v['fin']][$ancV] = $def;
      $nbre++;
      //echo "$ancV -> $def\n";
    }
  }
  echo "$nbre georefs indéfinis ou définis imprécisément\n";
  krsort($sortByDFin);
  echo Yaml::dump($sortByDFin);
  die();
}

if ($_GET['action']=='triParRef') {
  $sortByRef = [];
  foreach ($georefs as $ancV => $def) {
    //echo "$ancV -> $def\n";
    if ((substr($def, 0, 3) == '@AE') && ($def <> '@AE2020')) {
      $sortByRef[$def][$ancV] = $def;
    }
  }
  krsort($sortByRef);
  echo Yaml::dump($sortByRef);
}