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

En effet, différents référentiels sont définis par l'utilisation des codes INSEE :

  - On appelle **commune simple** une commune qui n'est ni associée, ni déléguée.
    Ces communes simples forment une partition du territoire, à condition de prendre comme territoire d'une communes issue
    d'une fusion-association, l'union des territoires des anciennes communes avant leur association.
  - On peut définir une autre partition d'une part en gérant à part les communes associées et,
    d'autre part, en substituant aux communes composées de communes déléguées ces dernières et aux communes PLM leurs arrondissements municipaux.
  
On peut donc en fait définir 2 référentiels qui forment chacun une partition du territoire :
    
  - celui des communes simples, cad en intégrant le territoire des communes associées
    à celui de leur commune de rattachement,
  - celui des entités élémentaires qui sont :
    - les communes associées et déléguées,
    - les arrondissements municipaux dans les communes PLM,
    - un éventuel complément quand les communes associées et déléguées ne couvrent pas la totalité du territoire
      d'une commune simple,
    - les communes simples qui ne comportent ni communes associées ni communes déléguées.
    
La solution retenue par l'IGN dans Admin-Express (AE) est assez confuse.
La version COG comporte une couche COMMUNE correspondant aux communes simples et une couche ENTITE_RATTACHEE correspondant aux communes associées ou déléguées et aux arrondissements municipaux ; cependant cette dernière couche n'est pas documentée.
De son côté, la version non COG ne gère pas de couche ENTITE_RATTACHEE mais la couche COMMUNE contient les arrondissements
municipaux.

Dans la suite je m'intéresse principalement au référentiel des communes simples.
La connaissance des communes rattachées est importante car elles correspondent à d'anciennes communes simples.

### Dénombrement

Le tableau ci-dessous fournit un dénombrement des communes simples et de leur évolution :

  - d'une part en distinguant les modifications au 1er janvier de celles en cours d'année,
  - d'autre part en fournissant
    - T : le nbre de communes simples à la fin de la période calculé sur le fichier INSEE à date,
    - \+ : le nbre d'identifiants créés sauf chgt de département,
    - \- : le nbre d'identifiants supprimés sauf chgt de département,
    - CD : le nbre d'identifiants créés et supprimés par chgt de département de communes,
    - M : le nbre d'identifiants en dehors des précédents dont la localisation a changé,
    - T' : le nbre de communes simples au début de la période calculé par T' = T - '+' + '-'.
  
| date | T | + | - | CD | M | T' | commentaire |
| - | - | - | - | - | - | - | - |
| 2020-01-01 | 34968 |  |  |  |  | 34968 | Limitation du nbre de modifications en raison des élections municipales de 2020|
| 2019-Z |  | 1 | 3 |  | 4 | 34970 | |
| 2019-01-01 | 34970 |  | 386 |  | 238 | 35356 | Incitations financières à la création de communes nouvelles|
| 2018-Z |  |  | 1 |  | 1 | 35357 | |
| 2018-01-01 | 35357 |  | 57 | 1 | 36 | 35414 | |
| 2017-Z |  |  | 2 |  | 1 | 35416 | |
| 2017-01-01 | 35416 | 1 | 392 |  | 182 | 35807 | Incitations financières à la création de communes nouvelles|
| 2016-Z |  |  | 78 | 1 | 19 | 35885 | Incitations financières à la création de communes nouvelles|
| 2016-01-01 |  |  | 708 | 1 | 306 | 36593 | Incitations financières à la création de communes nouvelles|
| 2015-Z |  |  | 65 |  | 11 | 36658 | Incitations financières à la création de communes nouvelles|
| 2015-01-01 |  |  | 24 |  | 13 | 36682 | Incitations financières à la création de communes nouvelles|
| 2014-Z |  | 1 | 1 |  |  | 36682 | |
| 2014-01-01 |  | 2 |  |  | 2 | 36680 | |
| 2013-Z |  |  | 1 |  | 1 | 36681 | |
| 2013-01-01 |  |  | 19 |  | 10 | 36700 | |
| 2012-01-01 |  | 5 | 2 |  | 6 | 36697 | |
| 2010-Z |  |  | 2 |  | 1 | 36682 | Entrée dans le Rpicom le 31 mars 2011 des 17 communes de Mayotte|
| 2010-01-01 | 36682 |  |  |  |  | 36682 | |
| 2009-01-01 |  |  | 1 |  | 1 | 36683 | |
| 2008-Z |  | 2 |  |  | 2 | 36681 | |
| 2008-01-01 |  | 2 |  |  | 2 | 36679 | |
| 2007-Z |  |  | 2 |  | 2 | 36681 | Sortie du Rpicom le 15 juillet 2007 de Saint-Barthélemy et de Saint-Martin|
| 2007-01-01 |  | 1 | 2 |  | 3 | 36682 | |
| 2006-Z |  | 1 | 2 |  | 2 | 36683 | |
| 2006-01-01 |  | 1 |  |  | 1 | 36682 | |
| 2005-01-01 |  | 1 |  |  | 1 | 36681 | |
| 2004-Z |  | 1 |  |  | 1 | 36680 | |
| 2004-01-01 |  | 2 |  |  | 1 | 36678 | |
| 2003-Z |  | 2 |  |  | 2 | 36676 | |
| 2003-01-01 |  |  | 1 |  | 1 | 36677 | |
| 2002-01-01 |  |  | 2 |  | 2 | 36679 | |
| 2001-Z |  | 5 | 1 |  | 5 | 36675 | |
| 2000-Z |  |  | 3 |  | 3 | 36678 | |
| 2000-01-01 | 36680 | 3 | 2 |  | 4 | 36679 | |
| 1999-01-01 |  | 1 |  |  | 1 | 36678 | |
| 1998-01-01 |  | 4 |  |  | 2 | 36674 | |
| 1997-Z |  | 2 |  |  | 2 | 36672 | |
| 1997-01-01 |  | 4 | 2 | 1 | 6 | 36670 | |
| 1996-01-01 |  |  | 2 |  | 1 | 36672 | |
| 1995-Z |  | 1 | 2 |  | 3 | 36673 | |
| 1994-01-01 |  | 1 | 3 |  | 4 | 36675 | |
| 1993-Z |  | 1 |  |  | 1 | 36674 | |
| 1993-01-01 |  | 1 | 1 |  | 2 | 36674 | |
| 1992-Z |  | 1 | 1 |  | 2 | 36674 | |
| 1992-01-01 |  | 8 | 1 |  | 4 | 36667 | |
| 1991-01-01 |  | 1 |  |  | 1 | 36666 | |
| 1990-Z |  | 4 | 2 |  | 2 | 36664 | |
| 1990-01-01 |  | 2 | 1 |  | 3 | 36663 | |
| 1989-Z |  | 5 | 1 |  | 7 | 36659 | |
| 1989-01-01 |  | 10 | 1 |  | 11 | 36650 | |
| 1988-01-01 |  | 8 | 1 |  | 6 | 36643 | |
| 1987-Z |  | 5 | 1 |  | 5 | 36639 | |
| 1987-01-01 |  | 7 |  |  | 5 | 36632 | |
| 1986-Z |  | 1 |  |  | 1 | 36631 | |
| 1986-01-01 |  | 15 |  |  | 10 | 36616 | |
| 1985-Z |  | 6 | 1 |  | 5 | 36611 | |
| 1985-01-01 |  | 14 | 1 |  | 14 | 36598 | |
| 1984-Z |  | 2 |  |  | 2 | 36596 | |
| 1984-01-01 |  | 22 |  |  | 9 | 36574 | |
| 1983-Z |  | 10 |  |  | 10 | 36564 | |
| 1983-01-01 |  | 23 | 2 |  | 18 | 36543 | |
| 1982-Z |  |  | 2 |  | 2 | 36545 | |
| 1982-01-01 |  | 11 |  |  | 10 | 36534 | |
| 1981-Z |  | 1 | 1 |  | 9 | 36534 | |
| 1981-01-01 |  | 8 |  |  | 4 | 36526 | |
| 1980-Z |  | 1 |  |  | 1 | 36525 | |
| 1980-01-01 |  | 16 | 2 |  | 11 | 36511 | |
| 1979-Z |  | 7 | 1 |  | 6 | 36505 | |
| 1979-01-01 |  | 11 | 2 |  | 9 | 36496 | |
| 1978-Z |  | 3 |  |  | 2 | 36493 | |
| 1978-01-01 |  | 4 | 2 |  | 6 | 36491 | |
| 1977-Z |  | 3 | 2 |  | 6 | 36490 | |
| 1977-01-01 |  | 4 | 8 |  | 7 | 36494 | |
| 1976-Z |  | 2 | 3 |  | 5 | 36495 | |
| 1976-01-01 |  | 1 | 4 | 360 | 5 | 36498 | Bi-départementalisation de la Corse|
| 1975-Z |  |  | 6 |  | 5 | 36504 | |
| 1975-01-01 |  |  | 22 |  | 21 | 36526 | |
| 1974-Z |  | 1 | 62 |  | 56 | 36587 | |
| 1974-01-01 |  |  | 74 |  | 60 | 36661 | |
| 1973-Z |  |  | 202 |  | 132 | 36863 | |
| 1973-01-01 |  |  | 481 |  | 313 | 37344 | |
| 1972-Z |  |  | 331 |  | 213 | 37675 | |
| 1972-01-01 |  |  | 29 |  | 22 | 37704 | |
| 1971-Z |  |  | 39 | 1 | 33 | 37743 | |
| 1971-01-01 |  |  | 27 | 1 | 24 | 37770 | |
| 1970-Z |  |  | 15 |  | 12 | 37785 | |
| 1970-01-01 |  |  | 7 |  | 7 | 37792 | |
| 1969-Z |  |  | 17 | 2 | 14 | 37809 | |
| 1969-01-01 |  |  | 13 |  | 13 | 37822 | |
| 1968-Z |  |  | 2 |  | 3 | 37824 | |
| 1968-01-01 |  |  | 3 | 506 | 3 | 37827 | Création des départements 91, 92, 93, 94 et 95|
| 1967-Z |  |  | 14 | 29 | 12 | 37841 | |
| 1967-01-01 |  |  | 10 |  | 9 | 37851 | |
| 1966-Z |  | 1 | 17 |  | 16 | 37867 | |
| 1966-01-01 |  |  | 8 |  | 8 | 37875 | |
| 1965-Z |  | 2 | 77 |  | 74 | 37950 | |
| 1965-01-01 |  |  | 49 |  | 44 | 37999 | |
| 1964-Z |  |  | 54 |  | 41 | 38053 | |
| 1964-01-01 |  |  | 10 |  | 9 | 38063 | |
| 1963-Z |  |  | 3 |  | 3 | 38066 | |
| 1963-01-01 |  |  | 5 |  | 5 | 38071 | |
| 1962-Z |  |  | 5 |  | 5 | 38076 | |
| 1962-01-01 |  |  | 1 |  | 1 | 38077 | |
| 1961-Z |  | 1 | 13 |  | 8 | 38089 | |
| 1961-01-01 |  |  | 3 |  | 3 | 38092 | |
| 1960-Z |  |  | 13 |  | 10 | 38105 | |
| 1960-01-01 |  |  | 2 |  | 2 | 38107 | |
| 1959-Z |  |  | 9 |  | 9 | 38116 | |
| 1959-01-01 |  | 1 |  |  | 1 | 38115 | |
| 1958-Z |  | 2 | 1 |  | 3 | 38114 | |
| 1958-01-01 |  | 1 |  |  | 2 | 38113 | |
| 1957-Z |  |  | 1 |  | 1 | 38114 | |
| 1957-01-01 |  |  | 1 |  | 1 | 38115 | |
| 1955-Z |  | 2 | 4 |  | 6 | 38117 | |
| 1954-Z |  | 5 | 1 |  | 6 | 38113 | |
| 1954-01-01 |  | 1 |  |  | 1 | 38112 | |
| 1953-Z |  | 3 | 7 |  | 9 | 38116 | |
| 1953-01-01 |  | 3 |  |  | 3 | 38113 | |
| 1952-Z |  | 2 | 2 |  | 4 | 38113 | |
| 1951-Z |  | 2 | 4 |  | 7 | 38115 | |
| 1950-Z |  | 9 | 8 |  | 19 | 38114 | |
| 1949-Z |  | 9 | 3 |  | 14 | 38108 | |
| 1948-Z |  | 5 | 1 |  | 9 | 38104 | |
| 1947-Z |  | 11 | 9 |  | 19 | 38102 | |
| 1946-Z |  | 4 | 8 |  | 11 | 38106 | |
| 1946-01-01 |  |  | 1 |  | 1 | 38107 | |
| 1945-Z |  | 1 | 8 |  | 5 | 38114 | |
| 1944-Z |  |  | 2 |  | 2 | 38116 | |
| 1943-Z |  |  | 8 |  | 4 | 38124 | |
| 1943-01-01 |  |  |  |  |  | 38124 | |

## Cas d'utilisation

### Actualisation d'un code INSEE périmé
Le cas d'utilisation le plus simple est l'utilisation dans une base métier d'un code INSEE qui correspondait
à une date donnée à une commune simple et qui maintenant n'y correspond plus.
Ce code n'existe plus dans la couche COMMUNE d'AE et l'utilisateur a besoin de le géocoder.
Le Rpicom fournit d'une part le code INSEE, valide à la date du référentiel, de la commune simple dans laquelle la commune
est localisée et, d'autre part, éventuellement, le code INSEE valide de l'entité rattachée dans laquelle la commune
est localisée.
Les 2 cas typiques sont ceux de la fusion et de l'association de communes : si une commune A fusionne dans la commune B
alors le code de A est remplacé par celui de B.
Par contre, si une commune A s'associe à une commune B alors le code de A est remplacé comme commune simple par celui de B
et reste le code A comme entité rattachée.

Ce mécanisme d'actualisation est fourni sous la forme d'une table de transcodage facilement utilisable
par exemple avec un SGBD relationnel par une semi-jointure. [Elle est disponible ici](transcode.csv).

### Géocodage d'un code INSEE à une date donnée
L'actualisation du code fournit le code d'une entité existante dont la localisation peut être plus grande que
la localisation initiale associée au code.
Dans certains cas, la localisation peut être retrouvée dans un ancien référentiel et peut alors être fournie à la demande.
C'est un 2ème cas d'utilisation où pour un code INSEE et une date donnée, les infos sur ce code sont retournées
ainsi que la géolocalisation associée.

Ce traitement est affectué par une api disponible à l'URL http://rpicom.geoapi.fr/
Pour retrouver la géolocalisation d'un code INSEE à une date utiliser l'appel http://rpicom.geoapi.fr/{code}/{date}
Un appel à un URL de la forme http://rpicom.geoapi.fr/api/{code} fournit la liste des versions que prend le code.

