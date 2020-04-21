# Utilisation du code INSEE des communes comme référentiel pivot

## Objectif de ce projet
L'objectif de ce projet est d'améliorer l'utilisation comme référentiel pivot du code INSEE des communes.

De nombreuses bases de données, par exemple des bases de décisions administratives, utilisent le code INSEE des communes
pour localiser leur contenu, c'est à dire dans l'exemple chaque décision administrative.

Or, ces codes INSEE évoluent, notamment en raison de la volonté de réduire le nombre de communes
par fusion et par création de communes nouvelles.
Ces codes devraient donc être modifiés dans la base pour en tenir compte.
Cependant ces modifications ne sont généralement pas effectuées
et en conséquence les codes INSEE ainsi contenus dans les bases perdent leur signification
car ils ne peuvent plus être croisés
avec un référentiel à jour des communes comme celui de l'INSEE ou une base géographique IGN comme Admin-Express.
Finalement, ils ne remplissent plus leur fonction de localisant.

Or, sur le fond, le code INSEE d'une commune disparue, par exemple fusionnée,
reste un localisant à condition de disposer du référentiel adhoc.
De plus, il peut être préférable de conserver un code INSEE périmé car en cas de rétablissement il redevient valide
et la conservation du code périmé dans la base évite alors des erreurs de localisation.

L'idée est donc de créer un nouveau référentiel appelé "référentiel pivot des codes INSEE des Communes" (RPiCom)
contenant tous les codes INSEE des communes ayant existé depuis le 1/1/1943
et associant à chacun des informations versionnées permettant de retrouver l'état de la commune
à une date donnée.  
Ainsi les codes INSEE intégrés un jour dans une base restent valables et peuvent être utilisés par exemple pour géocoder
l'information ou pour la croiser avec un référentiel à jour des communes.
Ce référentiel peut être généré à partir des informations du COG publiées par l'INSEE
et peut être géocodé à partir des informations d'Admin-Express publiées par l'IGN.

## Résultat (provisoire en test)
Le fichier [exrpicom.yaml](exrpicom.yaml) spécifie le schéma du référentiel ;
le champ $schema définit le schéma JSON des données et le champ contents donne un exemple de contenu.

Le fichier [rpicom.yaml](rpicom.yaml) contient le référentiel produit à partir du COG au 1/1/2020.

## Contexte juridique
Depuis quelques années, la liste des communes évolue conformément principalement aux textes suivants :

  - La loi « Marcellin » du 16 juillet 1971 créait la possibilité de fusionner des communes avec une possibilité,
    appelée fusion-association, de conserver des communes associées.
  - L'article 21 de la loi no 2010-1563 du 16 décembre 2010 de réforme des collectivités territoriales
    a remplacé ce mécanisme par la possibilité de regrouper des communes en créant des communes nouvelles.  
  - De plus, la loi du 16 mars 2015, dite loi Pélissard, a amélioré le régime des communes nouvelles et institué des 
    incitations financières pour en favoriser la création. Ainsi, les communes fusionnant en **2015** ou en **2016** 
    au sein de communes nouvelles de moins de 10 000 habitants se voyaient garantir pendant trois ans le niveau des 
    dotations de l’Etat.
    Le texte instaurait également des communes déléguées correspondant aux anciennes communes.  
  - Enfin, la loi de finances pour 2018 a prolongé ce dispositif d’incitations financières pour les communes créées
    entre le 2 janvier **2017** et le 1er janvier **2019**.

## Formalisation
### Formalisation des référentiels à une date donnée
L'INSEE attribue dans le COG à chaque commune et arrondissement municipal un identifiant, appelé code INSEE,
qui identifie des objets qui peuvent varier dans le temps.
Ainsi par exemple lorsque 2 communes fusionnent, le résultat réutilise généralement un des 2 identifiants.
De plus, en cas de rétablissement (c'est à dire dé-fusion),
la commune rétablie reprend le code qu'elle avait avant la fusion.

Par ailleurs, il est important de noter qu'à une date donnée, le même code INSEE peut identifier à la fois
une commune nouvelle et une de ses communes déléguées qui ne porte pas le même nom.
Par exemple la commune d'Arbignieu (01015) est devenue le 1/1/2016 commune nouvelle en prenant le nom de 'Arboys en Bugey',
en gardant le code 01015 et en ayant notamment une commune déléguée s'appellant 'Arbignieu' et portant le même code 01015.
Dans ce cas, il y a une ambigüité sur la localisation définie par un tel code INSEE.
Pour lever cette ambigüité, il est donc important de définir précisément le référentiel auquel on fait référence.

En effet, plusieurs référentiels sont définis par l'utilisation des codes INSEE :

  - On appelle **commune simple** une commune qui n'est ni associée, ni déléguée.
    Ces communes simples forment une partition du territoire, à condition de prendre comme territoire d'une communes issue
    d'une fusion-association, l'union des territoires des anciennes communes avant leur association.
  - On peut définir une seconde partition en gérant à part les communes associées.
  - Parmi les communes simples, certaines sont issues de la création d'une commune nouvelle et parmi ces dernières,
    certaines, que j'appelle **communes composites**, sont composées de communes déléguées qui en forment une partition.
    En substituant à ces communes composites leurs communes déléguées et aux communes PLM leurs arrondissements communaux,
    on définit une troisième partition.
  
Il existe donc en fait 3 référentiels qui forment chacun une partition du territoire :
    
  - celui des communes simples, cad en intégrant le territoire des communes associées
    à celui de leur commune de rattachement,
  - celui des communes simples et associées, cad en distinguant les communes associées de leur commune de rattachement,
  - celui des communes élémentaires, cad en remplacant les communes composites par leurs communes déléguées et
    les communes PLM par leurs arrondissements communaux.
    
Admin-Express de l'IGN gère les communes simples plus les arrondissements communaux.
C'est donc une variante du référentiel des communes simples, qui n'est pas une partition.

Dans la suite je m'intéresse principalement au référentiel des communes simples.

### Formalisation des évolutions
En tant que localisant un code INSEE correspond, pour un référentiel donné, et à une date donnée, à un certain territoire.
Plusieurs évènements ont pour conséquence de modifier ce territoire associé à une commune simple
et donc de changer la localisation associée à son code INSEE ;
il s'agit de :

  - création d'une commune nouvelle à partir de plusieurs communes existantes,
  - fusion de plusieurs communes en une seule,
  - rétablissement de certaines communes ayant précédemment été fusionnées,
  - association de plusieurs communes à une commune de rattachement,
  - rétablissement de certaines communes ayant précédemment été associées,
  - suppression d'une commune par répartition de son territoire dans plusieurs autres,
  - création d'une commune par contribution de territoire de plusieurs autres,
  - transfert de territoire d'une commune à une autre,
  - changement de rattachement conduisant au changement d'identifiant de la commune simple,
  - changement de département d'une commune conduisant au changement d'identifiant de la commune simple.
  
On peut regrouper ces opérations dans les 6 opérations suivantes :

  - l'agrégation qui associe un Id à un ens. d'Id (Set(Id) -> Id)
  - l'opération inverse de désagrégation (Set(Id) <- Id)
  - la suppression qui prend un Id et un ens. d'Id (Id, Set(Id) -> )
  - l'opération inverse de création (Set(Id) -> Id)
  - le changement d'identifiant (IdAncien -> IdNouveau)
  - le transfert de territoire d'une commune à une autre (IdSource -> IdDestination)

### Dénombrement

Le tableau ci-dessous fournit un dénombrement des communes et de leurs évolutions :

  - d'une part en distinguant les modifications au 1er janvier de celles en cours d'année,
  - d'autre part en fournissant
    - T : le nbre de communes simples à la fin de la période
    - \+ : le nbre d'identifiants créés sauf chgt de département,
    - \- : le nbre d'identifiants supprimés sauf chgt de département,
    - D- : le nbre d'identifiants créés et supprimés par chgt de département de communes,
    - M : le nbre d'identifiants en dehors des précédents dont la localisation a changée.
  
| année | T | + | - | CD | M | commentaire |
| - | - | - | - | - | - | - |
| 2020-01-01 | 34968 |  |  |  |  | |
| 2019-Z |  | 1 | 3 |  | 4 | |
| 2019-01-01 | 34970 |  | 386 |  | 238 | Incitations financières à la création de communes nouvelles|
| 2018-Z |  |  | 1 |  | 1 | |
| 2018-01-01 | 35357 |  | 57 | 1 | 36 | |
| 2017-Z |  |  | 2 |  | 1 | |
| 2017-01-01 | 35416 | 1 | 392 |  | 182 | Incitations financières à la création de communes nouvelles|
| 2016-Z |  |  | 78 | 1 | 19 | Incitations financières à la création de communes nouvelles|
| 2016-01-01 |  |  | 708 | 1 | 306 | Incitations financières à la création de communes nouvelles|
| 2015-Z |  |  | 65 |  | 11 | Incitations financières à la création de communes nouvelles|
| 2015-01-01 |  |  | 24 |  | 13 | Incitations financières à la création de communes nouvelles|
| 2014-Z |  | 1 | 1 |  |  | |
| 2014-01-01 |  | 2 |  |  | 2 | |
| 2013-Z |  |  | 1 |  | 1 | |
| 2013-01-01 |  |  | 19 |  | 10 | |
| 2012-01-01 |  | 5 | 2 |  | 6 | |
| 2010-Z |  |  | 2 |  | 1 | |
| 2010-01-01 | 36682 |  |  |  |  | |
| 2009-01-01 |  |  | 1 |  | 1 | |
| 2008-Z |  | 2 |  |  | 2 | |
| 2008-01-01 |  | 2 |  |  | 2 | |
| 2007-Z |  |  | 2 |  | 2 | |
| 2007-01-01 |  | 1 | 2 |  | 3 | |
| 2006-Z |  | 1 | 2 |  | 2 | |
| 2006-01-01 |  | 1 |  |  | 1 | |
| 2005-01-01 |  | 1 |  |  | 1 | |
| 2004-Z |  | 1 |  |  | 1 | |
| 2004-01-01 |  | 2 |  |  | 1 | |
| 2003-Z |  | 2 |  |  | 2 | |
| 2003-01-01 |  |  | 1 |  | 1 | |
| 2002-01-01 |  |  | 2 |  | 2 | |
| 2001-Z |  | 5 | 1 |  | 5 | |
| 2000-Z |  |  | 3 |  | 3 | |
| 2000-01-01 | 36680 | 3 | 2 |  | 4 | |
| 1999-01-01 |  | 1 |  |  | 1 | |
| 1998-01-01 |  | 4 |  |  | 2 | |
| 1997-Z |  | 2 |  |  | 2 | |
| 1997-01-01 |  | 4 | 2 | 1 | 6 | |
| 1996-01-01 |  |  | 2 |  | 1 | |
| 1995-Z |  | 1 | 2 |  | 3 | |
| 1994-01-01 |  | 1 | 3 |  | 4 | |
| 1993-Z |  | 1 |  |  | 1 | |
| 1993-01-01 |  | 1 | 1 |  | 2 | |
| 1992-Z |  | 1 | 1 |  | 2 | |
| 1992-01-01 |  | 8 | 1 |  | 4 | |
| 1991-01-01 |  | 1 |  |  | 1 | |
| 1990-Z |  | 4 | 2 |  | 2 | |
| 1990-01-01 |  | 2 | 1 |  | 3 | |
| 1989-Z |  | 5 | 1 |  | 7 | |
| 1989-01-01 |  | 10 | 1 |  | 11 | |
| 1988-01-01 |  | 8 | 1 |  | 6 | |
| 1987-Z |  | 5 | 1 |  | 5 | |
| 1987-01-01 |  | 7 |  |  | 5 | |
| 1986-Z |  | 1 |  |  | 1 | |
| 1986-01-01 |  | 15 |  |  | 10 | |
| 1985-Z |  | 6 | 1 |  | 5 | |
| 1985-01-01 |  | 14 | 1 |  | 14 | |
| 1984-Z |  | 2 |  |  | 2 | |
| 1984-01-01 |  | 22 |  |  | 9 | |
| 1983-Z |  | 10 |  |  | 10 | |
| 1983-01-01 |  | 23 | 2 |  | 19 | |
| 1982-Z |  |  | 2 |  | 2 | |
| 1982-01-01 |  | 11 |  |  | 10 | |
| 1981-Z |  | 1 | 1 |  | 9 | |
| 1981-01-01 |  | 8 |  |  | 4 | |
| 1980-Z |  | 1 |  |  | 1 | |
| 1980-01-01 |  | 16 | 2 |  | 11 | |
| 1979-Z |  | 7 | 1 |  | 6 | |
| 1979-01-01 |  | 11 | 2 |  | 9 | |
| 1978-Z |  | 3 |  |  | 2 | |
| 1978-01-01 |  | 4 | 2 |  | 6 | |
| 1977-Z |  | 3 | 2 |  | 6 | |
| 1977-01-01 |  | 4 | 8 |  | 7 | |
| 1976-Z |  | 2 | 3 |  | 5 | |
| 1976-01-01 |  | 1 | 4 | 360 | 5 | Bi-départementalisation de la Corse|
| 1975-Z |  |  | 6 |  | 5 | |
| 1975-01-01 |  |  | 22 |  | 21 | |
| 1974-Z |  | 1 | 62 |  | 56 | |
| 1974-01-01 |  |  | 74 |  | 60 | |
| 1973-Z |  |  | 202 |  | 132 | |
| 1973-01-01 |  |  | 481 |  | 313 | |
| 1972-Z |  |  | 331 |  | 213 | |
| 1972-01-01 |  |  | 29 |  | 22 | |
| 1971-Z |  |  | 39 | 1 | 33 | |
| 1971-01-01 |  |  | 27 | 1 | 24 | |
| 1970-Z |  |  | 15 |  | 12 | |
| 1970-01-01 |  |  | 7 |  | 7 | |
| 1969-Z |  |  | 17 | 2 | 14 | |
| 1969-01-01 |  |  | 13 |  | 13 | |
| 1968-Z |  |  | 2 |  | 3 | |
| 1968-01-01 |  |  | 3 | 506 | 3 | Création des départements 91, 92, 93, 94 et 95|
| 1967-Z |  |  | 14 | 29 | 12 | |
| 1967-01-01 |  |  | 10 |  | 9 | |
| 1966-Z |  | 1 | 17 |  | 16 | |
| 1966-01-01 |  |  | 8 |  | 8 | |
| 1965-Z |  | 2 | 77 |  | 74 | |
| 1965-01-01 |  |  | 49 |  | 44 | |
| 1964-Z |  |  | 54 |  | 41 | |
| 1964-01-01 |  |  | 10 |  | 9 | |
| 1963-Z |  |  | 3 |  | 3 | |
| 1963-01-01 |  |  | 5 |  | 5 | |
| 1962-Z |  |  | 5 |  | 5 | |
| 1962-01-01 |  |  | 1 |  | 1 | |
| 1961-Z |  | 1 | 13 |  | 8 | |
| 1961-01-01 |  |  | 3 |  | 3 | |
| 1960-Z |  |  | 13 |  | 10 | |
| 1960-01-01 |  |  | 2 |  | 2 | |
| 1959-Z |  |  | 9 |  | 9 | |
| 1959-01-01 |  | 1 |  |  | 1 | |
| 1958-Z |  | 2 | 1 |  | 3 | |
| 1958-01-01 |  | 1 |  |  | 2 | |
| 1957-Z |  |  | 1 |  | 1 | |
| 1957-01-01 |  |  | 1 |  | 1 | |
| 1955-Z |  | 2 | 4 |  | 6 | |
| 1954-Z |  | 5 | 1 |  | 6 | |
| 1954-01-01 |  | 1 |  |  | 1 | |
| 1953-Z |  | 3 | 7 |  | 9 | |
| 1953-01-01 |  | 3 |  |  | 3 | |
| 1952-Z |  | 2 | 2 |  | 4 | |
| 1951-Z |  | 2 | 4 |  | 7 | |
| 1950-Z |  | 9 | 8 |  | 19 | |
| 1949-Z |  | 9 | 3 |  | 14 | |
| 1948-Z |  | 5 | 1 |  | 9 | |
| 1947-Z |  | 11 | 9 |  | 19 | |
| 1946-Z |  | 4 | 8 |  | 11 | |
| 1946-01-01 |  |  | 1 |  | 1 | |
| 1945-Z |  | 1 | 8 |  | 5 | |
| 1944-Z |  |  | 2 |  | 2 | |
| 1943-Z |  |  | 8 |  | 4 | |
| 1943-01-01 | 38135 |  |  |  |  | |
  
## Cas d'utilisation

### Localisation d'un objet à l'intérieur d'une commune
On prend le cas de localisation d'une autorisation de travaux dans une commune.
Si le code INSEE associé à l'information ne correspond plus à une commune simple alors plusieurs cas:

  - l'identifiant correspond à une commune rattachée (associée ou déléguée) -> prendre la commune de rattachement ;
  - l'identifiant correspond à un arrondissement municipal -> prendre la commune de rattachement ;
  - l'identifiant correspond à une commune fusionnée -> c. dans laquelle elle a été fusionnée.
  - l'identifiant correspond à une commune supprimée -> son territoire a été réparti dans plusieurs c. ;
    il est impossible de savoir a priori dans laquelle se situe l'objet. Prendre la première en créant un risque d'erreur.
  - l'identifiant correspond à une commune à l'origine de rétablissement ;
    il est impossible de savoir a priori dans quelle commune rétablie se situe l'objet.
    Garder l'id. d'origine de la commune en créant un risque d'erreur.

### Association d'une information quantitative à une commune
On prend le cas d'association à une commune du nombre de permis de construire délivrés dans cette commune.

### Association d'une information qualitative à une commune
Exemple du classement d'une commune, par exemple classement d'une commune en zone vulnérable aux nitrates.
