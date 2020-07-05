<?php
{/*PhpDoc:
name: wikipedia.php
title: wikipedia.php - scrapping de wikipedia pour y récupérer des infos sur les anciennes communes
doc: |
  Wikipedia est riche sur les anciennes communes supprimées.
  L'objectif est de récupérer les coordonnées des anciennes communes.
  J'en profite pour constituer une "base communale" estraite de Wikipedia.
  J'indexe les communes par département et par l'URI Wikipédia.
  Je crée un fichier coms.yaml structuré à un premier niveau par département et à un second niveau par page Wikipédia décrivant
  une commune.
  Je constitue un cache de ces pages dans le répertoire coms avec comme nom la concaténation du no de dépt et l'URI Wikipedia.

  Méthode:
   - Je pars de la page Wikipedia donnant la liste des anciennes communes de France, je la télécharge et la stocke ds listeacfr.html
   - Je télécharge les pages départementales référencées et les stocke sous le nom dxx.html où xx est le code du département
   - j'extrait de chaque page de département la liste des communes avec son href et son nom, j'enregistre le résultat dans coms.yaml
     - toutes les pages de département ne sont pas codées de la même manière
     - je n'analyse pas les pages en détail et peut générer des communes existantes
   - je télécharge la page de chaque commune et je l'enregistre
   - j'analyse la page de chaque commune pour en extraire sa position géographique
   - Le résultat est le fichier comgeos.yaml
   - Par ailleurs, je maintiens un fichier comgeos2.yaml qui enregistre les coordonnées des communes abrogées définies par l'INSEE
     dont les coordonnées ne sont pas définies par Wikipédia.
   - le script bzonwp.php teste pour les fichiers Insee si les coordonnées sont ou non définies.
*/}

require_once __DIR__.'/../../../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>wkpd</title></head><body><pre>\n";
if (!is_file(__DIR__.'/listeacfr.yaml')) { // téléchargement de la page racine et extraction des url des anciennes communes par dept
  if (!is_file(__DIR__.'/listeacfr.html'))
    file_put_contents(__DIR__.'/listeacfr.html',
      file_get_contents('https://fr.wikipedia.org/wiki/Listes_des_anciennes_communes_de_France'));

  $html = file_get_contents(__DIR__.'/listeacfr.html');
  $pattern = '!<li>(..)&#160;: <a href="(/wiki/Liste_des_anciennes_communes_d[^"]*)" (class="mw-redirect" )?'
    .'title="(Liste des anciennes communes d[^"]*)">Liste des anciennes communes d[^<]*</a>!';
  while (preg_match($pattern, $html, $matches)) {
    echo "d$matches[1]:\n  title: $matches[4]\n  href: $matches[2]\n";
    $html = preg_replace($pattern, '', $html, 1);
  }
  die();
}
elseif (!is_file(__DIR__.'/d01.html')) { // téléchargement des fichiers html des départements
  $listeacfr = Yaml::parse(file_get_contents(__DIR__.'/listeacfr.yaml'));
  foreach ($listeacfr as $id => $acfr) {
    echo "$id : <a href='$acfr[href]'>$acfr[title]</a>\n";
    if (!is_file(__DIR__."/$id.html")) {
      file_put_contents(__DIR__."/$id.html", file_get_contents("https://fr.wikipedia.org$acfr[href]"));
    }
  }  
}
elseif (!is_file(__DIR__.'/coms.yaml')) { // analyse de chaque fichier par département et génération du fichier coms.yaml des communes
  // structuré par département avec une entrée par page wikipedia correspondant à une commune
  // à laquelle peuvent correspondre plusieurs noms
  $listeacfr = Yaml::parse(file_get_contents(__DIR__.'/listeacfr.yaml'));
  $patterns = [
    // Page structurée sans table
    '!<a href="/wiki/([^"]*)" (class="mw-redirect" )?title="([^"]*)">([^<]*)</a>(&#160;| )&gt; !' => ['idcom'=> 1, 'name'=> 4],
    // Page structurée avec tables et une case par commune abrogée
    '!<td>(<span data-sort-value="[^"]+">)?<a href="/wiki/([^"]+)" title="([^"]+)">([^<]+)</a>!' => ['idcom'=> 2, 'name'=> 4],
    // Page structurée avec tables et une case pour plusieurs communes abrogées
    // ex: <td bgcolor="#FEDFDF"><a href="/wiki/Annappes" title="Annappes">Annappes</a>, <a href="/wiki/Ascq" title="Ascq">Ascq</a> et <a href="/wiki/Flers-lez-Lille" title="Flers-lez-Lille">Flers-lez-Lille</a>\n</td>
    // ex: <td bgcolor="#FEDFDF"><a href="/wiki/Beaumont_(Pas-de-Calais)" class="mw-redirect" title="Beaumont (Pas-de-Calais)">Beaumont</a> et <a href="/wiki/H%C3%A9nin-Li%C3%A9tard" class="mw-redirect" title="Hénin-Liétard">Hénin-Liétard</a>\n</td>
    '!(<td[^>]*>)(|, | et )<a href="/wiki/([^"]+)" (class="[^"]*" )?title="([^"]+)">([^<]+)</a>!' => [
      'idcom'=> 3, 'name'=> 6, 'replace'=> 1],
  ];
  $pattern3 = '!<a href="/wiki/([^"]+)" title="([^"]+)">([^<]+)</a>!';
  $nbcoms = 0;
  foreach ($listeacfr as $iddept => $acfr) {
    //if ($iddept <> 'd59') continue;
    $html = file_get_contents(__DIR__."/$iddept.html");
    $onePAtternMatches = true;
    while ($onePAtternMatches) { // je boucle tt que au moins 1 pattern matches
      $onePAtternMatches = false;
      foreach ($patterns as $pattern => $vars) {
        if (preg_match($pattern, $html, $matches)) {
          $replace = isset($vars['replace']) ? $matches[$vars['replace']] : '';
          $html = preg_replace($pattern,  $replace, $html, 1);
          $idcom = $matches[$vars['idcom']];
          $name = str_replace('&#39;', "'", $matches[$vars['name']]);
          if ($idcom <> 'Listes_des_anciennes_communes_de_France') {
            if (!isset($coms[$iddept][$idcom]))
              $coms[$iddept][$idcom] = ['names'=> [$name]];
            elseif (!in_array($name, $coms[$iddept][$idcom]['names']))
              $coms[$iddept][$idcom]['names'][] = $name;
            $nbcoms++;
          }
          $onePAtternMatches = true;
        }
      }
    }
  }
  echo Yaml::dump($coms);
  die("Fin ligne ".__LINE__.", $nbcoms générées\n");
}
/*elseif (0) {
  // manque depts 2 15 59 62 75 77 78 85 87 91-95 97* manque encore 7
  $pattern = '!<td><a href="/wiki/([^"]+)" title="([^"]+)">[^<]+</a>!';
  foreach (['02','15','59','62','77','78','85','87'] as $cdept) {
    $html = file_get_contents(__DIR__."/d$cdept.html");
    while (preg_match($pattern, $html, $matches)) {
      $coms["d$cdept"][$matches[1]] = ['name'=> $matches[2]];
      $html = preg_replace($pattern, '', $html, 1);
    }
  }
  echo Yaml::dump($coms);
  die("Fin ligne ".__LINE__."\n");
}
elseif (1) {
  // possibilité d'une balise span
  $pattern = '!<td>(<span data-sort-value="[^"]+">)<a href="/wiki/([^"]+)" title="([^"]+)">([^<]+)</a>!';
  foreach (['02','15','59','62','77','78','85','87'] as $cdept) {
    $html = file_get_contents(__DIR__."/d$cdept.html");
    while (preg_match($pattern, $html, $matches)) {
      $coms["d$cdept"][$matches[2]] = ['name'=> str_replace('&#39;', "'", $matches[4])];
      $html = preg_replace($pattern, '', $html, 1);
    }
  }
  echo Yaml::dump($coms);
  die("Fin ligne ".__LINE__."\n");
}*/
/*elseif (0) { // suppression du nom de département lorsqu'il a été rajouté dans le nom de commune
  // dans un premier temps mise au point d'un fichier des noms des départements puis suppression du nom de département et affichage
  $depts = Yaml::parse(file_get_contents(__DIR__.'/departements.yaml'));
  $depts = $depts['contents'];
  $coms = Yaml::parse(file_get_contents(__DIR__.'/coms.yaml'));
  foreach ($coms as $iddept => $dept) {
    foreach ($dept as $idcom => $com) {
      //echo "$com[name]\n";
      if (preg_match('! \(([^)]*)\)!', $com['name'], $matches)) {
        //echo "  match $com[name] -> $matches[1]\n";
        //$nomdepts[$matches[1]] = 1;
        if (in_array($matches[1], $depts)) {
          $name = substr($com['name'], 0, strlen($com['name'])-strlen($matches[1])-3);
          //echo "$com[name] - $name\n";
          $coms[$iddept][$idcom]['name'] = $name;
        }
      }
    }
  }
  /*foreach (array_keys($nomdepts) as $nomdept) {
    echo "$nomdept\n";
  }*/
  /*echo Yaml::dump($coms);
  die("Fin ligne ".__LINE__."\n");
}*/
else { // téléchargement des fichiers de chaque commune et extraction des coords géo pour créer comgeos.yaml
  $gengeo = true; // extraction des coords géo et création de comgeos.yaml
  //$gengeo = false; // téléchargement des fichiers de chaque commune
  $coms = Yaml::parse(file_get_contents(__DIR__.'/coms.yaml'));
  foreach ($coms as $iddept => $dept) {
    echo "iddept=$iddept\n";
    foreach ($dept as $idcom => $com) {
      if (!is_file(__DIR__."/coms/$iddept-$idcom.html")) {
        if ($gengeo) {
          file_put_contents(__DIR__.'/comgeos.yaml', Yaml::dump($coms));
          die("Arrêt en attendant le téléchargement\n");
        }
        echo "  idcom=$idcom\n";
        if (!($html = file_get_contents("https://fr.wikipedia.org/wiki/$idcom")))
          die("Erreur sur téléchargement de https://fr.wikipedia.org/wiki/$idcom\n");
        file_put_contents(__DIR__."/coms/$iddept-$idcom.html", $html);
        sleep(2);
      }
      if ($gengeo) {
        $html = file_get_contents(__DIR__."/coms/$iddept-$idcom.html");
        $pattern = '!<a class="mw-kartographer-maplink" data-mw="[^"]*" data-style="osm-intl" href="[^"]*"'
          .' data-zoom="(\d+)" data-lat="([^"]+)" data-lon="([^"]+)" data-overlays="[^"]*">!';
        if (!preg_match($pattern, $html, $matches))
          echo "No match ligne ".__LINE__." sur $idcom <a href='coms/$iddept-$idcom.html'>source</a>\n";
        else {
          $geo = [(float)$matches[3], (float)$matches[2]];
          $dept[$idcom]['geo'] = $geo;
        }
      }
    }
    //echo Yaml::dump($dept);
    if ($gengeo)
      $coms[$iddept] = $dept;
    //die("Fin $iddept");
  } 
  if ($gengeo) {
    file_put_contents(__DIR__.'/comgeos.yaml', Yaml::dump($coms));
  }
  die("Fin lecture des communes\n");
}