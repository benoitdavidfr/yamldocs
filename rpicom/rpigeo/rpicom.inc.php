<?php
/*PhpDoc:
name: rpicom.inc.php
title: rpicom.inc.php - def. des classes Rpicom, Version et Evt pour gérer le Rpicom
screens:
doc: |
  Charge le Rpicom, version Insee, depuis le stockage en PostgreSql
  Traduit les relations entre versions en relations topologiques entre zones géographiques:
    - sameAs pour identité des zones géographiques entre 2 versions
    - includes(a,b) pour inclusion de b dans a
  Ces relations topologiques permettront dans la classe Zone de construire les zones géographiques
  et les relations d'inclusion entre elles.
journal: |
  30/6/2020:
    - ajout d'une relation déduite
  28/6/2020:
    - appariement des zones du COG2020 ok
  25/6/2020:
    - première version
*/

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

class Rpicom {
  static $all=[]; // [cinsee => Rpicom] - tous les Rpicom par code Insee
  protected $cinsee;
  protected $versions=[]; // [ dCreation => Version ]
  
  // Charge la structure Rpicom depuis PgSql
  static function loadFromPg(string $where = '') {
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
      //echo '<pre>',Yaml::dump($tuple),'</pre>';
      Rpicom::add($tuple);
      if (is_null($tuple['fin']))
        Stats::incr('eadminv/fin=null');
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
    ksort(self::$all);
    foreach (self::$all as $rpicom)
      krsort($rpicom->versions);
  }
  
  // construit la structure
  static function add(array $tuple) {
    if (!isset(self::$all[$tuple['cinsee']]))
      self::$all[$tuple['cinsee']] = new self($tuple);
    else
      self::$all[$tuple['cinsee']]->addVersion($tuple);
  }
  
  static function allAsArray(): array {
    $all = [];
    foreach (self::$all as $cinsee => $rpicom) {
      $all[$cinsee] = $rpicom->asArray();
    }
    return $all;
  }
  
  function __construct(array $tuple) {
    $this->cinsee = $tuple['cinsee'];
    $this->addVersion($tuple);
  }
  
  // renvoie la version du Rpicom correspondant à une date dé but donnée
  function version(string $dCreation): Version {
    if (!isset($this->versions[$dCreation]))
      throw new Exception("Erreur, la version $dCreation du Rpicom $this->cinsee n'existe pas");
    return $this->versions[$dCreation];
  }
  
  // renvoie soit le Rpicom soit la version en fonction de son identifiant
  static function get(string $id) {
    $cinsee = substr($id, 1, 5);
    if (!isset(self::$all[$cinsee])) {
      echo "Rpicom $cinsee n'existe pas";
      throw new Exception("Rpicom $cinsee n'existe pas");
    }
    if (strlen($id) == 6) {
      return self::$all[$cinsee];
    }
    else {
      $dCreation = substr($id, 7);
      if (!isset(self::$all[$cinsee]->versions[$dCreation]))
        throw new Exception("Version $dCreation du Rpicom $cinsee n'existe pas");
      //echo "get($id)->"; print_r(self::$all[$cinsee]->versions[$dCreation]);
      return self::$all[$cinsee]->versions[$dCreation];
    }
  }
  
  function addVersion(array $tuple) {
    if (!isset($this->versions[$tuple['dcreation']])) {
      $this->versions[$tuple['dcreation']] = new Version($tuple);
    }
    elseif ($tuple['statut']=='cDéléguée') {
      $this->versions[$tuple['dcreation']]->setNomCDéléguée($tuple['nom']);
    }
    else {
      echo Yaml::dump([$this->versions[$tuple['dcreation']], $tuple]);
      throw new Exception("Existe déjà $tuple[cinsee]");
    }
  }
  
  function addEvtCreation(array $tuple) {
    $this->versions[$tuple['dcreation']]->setEvtCreation($tuple['evt']);
  }
  
  function asArray(): array {
    krsort($this->versions);
    $array = [];
    foreach ($this->versions as $dCreation => $version) {
      $varray = $version->asArray();
      unset($varray['dCreation']);
      $array[$dCreation] = $varray;
    }
    return $array;
  }
  
  // dernière version (dans le temps)
  function lastVersion(): Version { return $this->versions[array_keys($this->versions)[0]]; }
  
  // Fabrique ttes les zones
  static function buildAllZones(): void {
    foreach (self::$all as $cinsee => $rpicom)
      $rpicom->buildZones();
    Zone::traiteInclusions();
  }
  
  // Fabrique les zones corr. à un Rpicom
  function buildZones(): void {
    /* gère dans un premier temps le cas illustré par 27111 de fusion suivie d'un rétablissement
    27111:
      '1947-12-19': {dFin: null, statut: s, crat: null, nom: Bretagnolles, evtFin: null, evtCreation: {rétablieCommeSimpleDe: 27078 } }
      '1943-01-01': {dFin: '1943-12-01', statut: s, crat: null, nom: Bretagnolles, evtFin: {fusionneDans: 27078 } }
    */
    $dCreations = array_keys($this->versions);
    foreach ($dCreations as $noVersion => $dCreation) {
      $version = $this->versions[$dCreation];
      if ($version->evtCreation() && ($version->evtCreation()->key0() == 'rétablieCommeSimpleDe')) {
        if (isset($dCreations[$noVersion+1])) {
          $dCreation2 = $dCreations[$noVersion+1];
          $version2 = $this->versions[$dCreation2];
          if ($version2->evtFin()->key0() == 'fusionneDans') {
            //echo "Fusion/rétablissement détectée pour ",$version2->id()," et ",$version->id(),"\n";
            Zone::sameAs($version2->id(), $version->id());
          }
        }
      }
    }
    //return;
    
    foreach ($this->versions as $version) {
      $version->buildZones();
    }
  }
  
  // retourne l'id d'une entité définie par son code INSEE et une date à laquelle elle existe
  static function idByCinseeAndDate(string $cinsee, string $date): ?string {
    $rpicom = self::$all[$cinsee];
    foreach ($rpicom->versions as $dCreation => $version) {
      if ((strcmp($date, $dCreation) >= 0) && (!$version->dFin() || (strcmp($date, $version->dFin()) < 0)))
        return $version->id();
    }
    return null;
  }
  
  // retourne la version défnie par son code Insee et sa date de fin
  static function versionParCinseeEtDateDeFin(string $cinsee, string $dFin): ?Version {
    $rpicom = self::$all[$cinsee];
    foreach ($rpicom->versions as $dCreation => $version) {
      if ($version->dFin() == $dFin)
        return $version;
    }
    return null;
  }
};

class Version {
  // recodage des statuts
  static $statuts = [
    'cSimple'=>'s',
    'cAssociée'=>'r',
    'cDéléguée'=>'r',
    'ardtMun'=>'r',
  ];
  protected $cinsee; // code insee
  protected $dCreation; // date de création
  protected $dFin; // date de fin ssi périmée sinon null
  protected $statut; // statut simplifié - 's' pour simple, 'r' pour rattachée
  protected $crat; // null ssi s sinon code insee de la commune de rattachement
  protected $nom; // nom
  protected $evtFin; // evt de fin : null si version valide, Evt si version périmée
  protected $evtCreation; // evt de création ou null
  protected $nomCDeleguee; // si le code et la date correspondent à la fois à une c.s. et à une c.d. alors nom de la c.d.

  function __construct(array $tuple) {
    $this->cinsee = $tuple['cinsee'];
    $this->dCreation = $tuple['dcreation'];
    $this->dFin = $tuple['fin'];
    $this->statut = self::$statuts[$tuple['statut']];
    $this->crat = $tuple['crat'] ? $tuple['crat'] : null;
    $this->nom = $tuple['nom'];
    $this->evtFin = $tuple['evtfin'] ? new Evt($tuple['evtfin']) : null;
    $this->evtCreation = null;
    $this->nomCDeleguee = null;
  }
  
  function dCreation(): string { return $this->dCreation; }
  function dFin(): ?string { return $this->dFin; }
  function statut(): string { return $this->statut; }
  function evtFin(): ?Evt { return $this->evtFin; }
  function evtCreation(): ?Evt { return $this->evtCreation; }
  
  function id(): string { return $this->statut.$this->cinsee.'@'.$this->dCreation; }
  
  function setNomCDéléguée(string $nom): void { $this->nomCDeleguee = $nom; }
  
  function setEvtCreation(string $evt): void { $this->evtCreation = new Evt($evt); }
  
  function asArray(): array {
    $array = [
      'dCreation'=> $this->dCreation,
      'dFin'=> $this->dFin,
      'statut'=> $this->statut,
      'crat'=> $this->crat,
      'nom'=> $this->nom,
      'evtFin'=> $this->evtFin ? $this->evtFin->asArray() : null,
    ];
    if ($this->evtCreation)
      $array['evtCreation'] = $this->evtCreation->asArray();
    if ($this->nomCDeleguee)
      $array['nomCDeleguee'] = $this->nomCDeleguee;
    return $array;
  }
  
  function __toString(): string {
    return json_encode(array_merge(['cinsee'=> $this->cinsee], $this->asArray()), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  }
  
  function isValid(): bool { return is_null($this->dFin); }
    
  function next(): ?Version { // version suivante dans le temps
    return Rpicom::$all[$this->cinsee]->version($this->dFin);
  }
  
  //function estSimple(): bool { return ($this->statut == 's'); }
  
  function buildZones(): void { // construit les zones correspondant à une version
    //echo "buildZones() @ $this\n";
    // définition de la relation à la date de création de la version
    if ($this->statut <> 's') { // cas d'une commune rattachée, elle est incluse dans sa rattachante
      Zone::includes(Rpicom::idByCinseeAndDate($this->crat, $this->dCreation), $this->id());
    }
    elseif ($this->nomCDeleguee) { // cas particulier où la version représente la cs et la cd
      // la déléguée propre est incluse dans la simple
      Zone::includes($this->id(), 'r'.$this->cinsee.'@'.$this->dCreation);
    }
    else { // commune standard doit être créée si n'intervient jamais dans une inclusion ou un sameAs
      Zone::getOrCreate($this->id());
    }
    
    // définition de la relation entre la version courante et la version qui suit dans le temps
    if (is_null($this->evtFin)) {
    }
    elseif ($this->evtFin->isString()) {
      switch ($this->evtFin->asString()) {
        case 'Commune déléguée rétablie comme commune simple':
        case 'Commune associée rétablie comme commune simple':
        case 'Absorbe certaines de ses c. rattachées ou certaines de ses c. associées deviennent déléguées': {
          Zone::sameAs($this->id(), $this->next()->id());
          break;
        }
        
        case 'Commune rétablissant des c. rattachées ou fusionnées': {
          Zone::includes($this->id(), $this->next()->id()); // la version suivante est incluse dans la version courante
          break;
        }
        
        case 'Sort du périmètre du Rpicom': {
          break;
        }
        
        case 'Commune rattachée devient commune de rattachement': {
          //echo "Cas 'Commune rattachée devient commune de rattachement' sur $this->cinsee à voir ligne ",__LINE__,"\n";
          break;
        }
        
        default: {
          throw new Exception("$this evt $this->evtFin");
        }
      }
    }
    elseif (count($this->evtFin->asArray()) == 1) {
      $key0 = $this->evtFin->key0();
      switch ($key0) {
        case 'changeDeNomPour':
        case 'devientDéléguéeDe':
        case 'sAssocieA':
        case 'resteAssociéeA':
        case 'resteDéléguéeDe':
        case 'changedAssociéeEnDéléguéeDe': {
          Zone::sameAs($this->id(), $this->next()->id());
          break;
        }
        
        case 'seFondDans':
        case 'fusionneDans': {
          if ($this->statut == 's') {
            $crat = $this->evtFin->asArray()[$key0];
            Zone::includes("s$crat@$this->dFin", $this->id());
          }
          break;
        }
        
        case 'seDissoutDans': break; // il n'y a plus rien après
        
        case 'absorbe': {
          //echo Yaml::dump(['this'=> $this->asArray()]);
          $statuts = [];
          foreach ($this->evtFin->asArray()[$key0] as $cinseeAbsorbee) {
            $absorbee = Rpicom::versionParCinseeEtDateDeFin($cinseeAbsorbee, $this->dFin);
            $statuts[$absorbee->statut] = 1;
          }
          if (isset($statuts['s'])) { // si au moins une des absorbée est une c.s. alors l'absorbante grossit
            Zone::includes($this->next()->id(), $this->id());
            //echo "  Zone::includes(",$this->next()->id(),", ",$this->id(),");\n";
          }
          else { // sinon l'absorbante est identique avant et après
            Zone::sameAs($this->next()->id(), $this->id());
            //echo "  Zone::sameAs(",$this->next()->id(),", ",$this->id(),");\n";
          }
          break;
        }
        
        case 'prendPourAssociées': { // la rattachante grossit
          Zone::includes($this->next()->id(), $this->id());
          break;
        }
        
        case 'délègueA': {
          $deleguees = $this->evtFin->asArray()[$key0];
          
          if ($deleguees == [$this->cinsee])
            // cas très particulier où la seule déléguée est elle-même, ce qui ne doit jamais exister
            Zone::sameAs($this->next()->id(), $this->id());
          else {
            // la commune rattachante s'agrandit
            Zone::includes($this->next()->id(), $this->id());
            if (in_array($this->cinsee, $deleguees)) { // si auto-déléguée
              // la version actuelle est égale à l'auto-déléguée créée
              Zone::sameAs($this->id(), 'r'.$this->cinsee.'@'.$this->dFin);
              //echo "Zone::sameAs(d",$this->cinsee.'@'.$this->dFin,', ', $this->id(),");\n";
            }
          }
          break;
        }
        
        case 'contribueA': {
          Zone::includes($this->id(), $this->next()->id()); // la version suivante est incluse dans la version courante
          break;
        }

        case 'rétablitCommeSimple': {
          Zone::includes($this->id(), $this->next()->id()); // la version suivante est incluse dans la version courante
          foreach ($this->evtFin->asArray()[$key0] as $nvCinsee)
            Zone::includes($this->id(), "s$nvCinsee@".$this->dFin); // chaque c. rétablie est incluse dans la version courante
          break;
        }
        
        case 'reçoitUnePartieDe': {
          Zone::includes($this->next()->id(), $this->id()); // la version suivante inclus la version courante
          break;
        }

        case 'changeDeRattachementPour': {
          Zone::sameAs($this->next()->id(), $this->id());
          break;
        }

        case 'perdRattachementPour': {
          // la zone de la c. actuelle est identique à celle de la future rattachante
          $nlleRat = $this->evtFin->asArray()[$key0];
          Zone::sameAs($this->id(), "s$nlleRat@".$this->dFin);
          // la future zone de la c. actuelle est identique à la zone de la commune au 1/1/1943
          // Cela permet de donner cette relation avec la version avant associations
          Zone::sameAs('r'.$this->cinsee.'@'.$this->dFin, 's'.$this->cinsee.'@1943-01-01');
          break;
        }
        
        case 'quitteLeDépartementEtPrendLeCode': {
          $nvCinsee = $this->evtFin->asArray()[$key0];
          //echo "$this->cinsee $key0 -> $nvCinsee\n";
          Zone::sameAs($this->id(), Rpicom::idByCinseeAndDate($nvCinsee, $this->dFin));
          //echo "Zone::sameAs(",$this->id(),", ",Rpicom::idByCinseeAndDate($nvCinsee, $this->dFin),");\n";
          break;
        }
        
        default: {
          throw new Exception("$this evt $this->evtFin");
        }
      }
    }
    elseif (count($this->evtFin->asArray()) > 1) {
      $evtFin = $this->evtFin->asArray();
      // "evtFin":{"absorbe":["01055"],"prendPourAssociées":["01440"]}
      if (array_keys($evtFin) == ['absorbe','prendPourAssociées']) {
        Zone::includes($this->next()->id(), $this->id());
      }
      // "evtFin":{"absorbe":["08068"],"délègueA":["08490","08443","08493"]}
      elseif (array_keys($evtFin) == ['absorbe','délègueA']) {
        Zone::includes($this->next()->id(), $this->id());
        if (in_array($this->cinsee, $evtFin['délègueA']))
          // la version initiale est identique à la déléguée suivante
          Zone::sameAs($this->id(), 'r'.$this->cinsee.'@'.$this->dFin);
      }
      // "evtFin":["Commune associée rétablie comme commune simple",{"prendPourAssociées":[55273]}]
      elseif ((array_keys($evtFin)==[0,1]) && ($evtFin[0]=='Commune associée rétablie comme commune simple')
          && (array_keys($evtFin[1])[0]=='prendPourAssociées')) {
        Zone::includes($this->next()->id(), $this->id());
      }
      // "evtFin":["Commune associée rétablie comme commune simple",{"sAssocieA":55386}]
      elseif  ((array_keys($evtFin)==[0,1]) && ($evtFin[0]=='Commune associée rétablie comme commune simple')
          && (array_keys($evtFin[1])[0]=='sAssocieA')) {
        Zone::sameAs($this->next()->id(), $this->id());
      }
      // "evtFin":[{"absorbe":[14507]},{"quitteLeDépartementEtPrendLeCode":50649}]}
      elseif ((array_keys($evtFin)==[0,1]) && (array_keys($evtFin[0])[0]=='absorbe')
          && (array_keys($evtFin[1])[0]=='quitteLeDépartementEtPrendLeCode')) {
        $statuts = [];
        foreach ($evtFin[0]['absorbe'] as $cinseeAbsorbee) {
          $absorbee = Rpicom::versionParCinseeEtDateDeFin($cinseeAbsorbee, $this->dFin);
          $statuts[$absorbee->statut] = 1;
        }
        $nvCinsee = $evtFin[1]['quitteLeDépartementEtPrendLeCode'];
        $idSuivant = Rpicom::idByCinseeAndDate($nvCinsee, $this->dFin);
        if (isset($statuts['s'])) // si au moins une des absorbée est une c.s. alors l'absorbante grossit
          Zone::includes($idSuivant, $this->id());
        else // sinon l'absorbante est identique avant et après
          Zone::sameAs($idSuivant, $this->id());
      }
      // "evtFin":[{"changeDeNomPour":"Mœurs-Verdey"},{"reçoitUnePartieDe":51606}]}
      elseif ((array_keys($evtFin)==[0,1]) && (array_keys($evtFin[0])[0]=='changeDeNomPour')
          && (array_keys($evtFin[1])[0]=='reçoitUnePartieDe')) {
        Zone::includes($this->next()->id(), $this->id()); // la version suivante inclus la version courante
      }
      else
        throw new Exception("$this evt $this->evtFin");
    }
    else
      throw new Exception("$this evt $this->evtFin");
  }
};

class Evt {
  protected $evt;
  
  function __construct($evt) { $this->evt = json_decode($evt, true); }
  function asArray() { return $this->evt; }
  function __toString(): string {
    return is_string($this->evt) ? $this->evt : json_encode($this->evt, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  }
  function isString(): bool { return is_string($this->evt); }
  function asString(): ?string { return is_string($this->evt) ? $this->evt : null; }
  function isArray(): bool { return is_array($this->evt); }
  
  function key0(): string { return $this->isArray() ? array_keys($this->evt)[0] : ''; }
};

