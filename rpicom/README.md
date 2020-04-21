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
Finalement, ils ne remplissent leur fonction de localisant.

Or sur le fond le code INSEE d'une commune disparue, par exemple fusionnée,
reste un localisant à condition de disposer du référentiel adhoc.
De plus, il peut être préférable de conserver un code INSEE périmé car en cas de rétablissement il redevient valide
et la conservation du code périmé dans la base évite des erreurs de localisation.

L'idée est donc de créer un nouveau référentiel appelé "référentiel pivot des codes INSEE des Communes" (RPiCom)
contenant tous les codes INSEE des communes ayant existé depuis le 1/1/1943
et en associant à chacun des informations versionnées permettant de retrouver l'état de la commune
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
    
  - celui des communes simples, cad en intégrant les communes associées à leur commune de rattachement,
  - celui des communes simples et associées, cad en distinguant les communes associées de leur commune de  rattachement,
  - celui des communes élémentaires, cad en remplacant les communes composites par leurs communes déléguées et
    les communes PLM par leurs arrondissements communaux.
    
Admin-Express de l'IGN gère les communes simples plus les arrondissements communaux.
C'est donc une variante du référentiel des communes simples qui n'est pas une partition.

Dans la suite je m'intéresse principalement au référentiel des communes simples.

### Formalisation des évolutions
En tant que localisant un code INSEE correspond, dans un référentiel donné, et à une date donnée, à un certain territoire.
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
  
On peut formaliser ces opérations par :

  - l'opération d'agrégation qui associe un Id à un ens. d'Id (Set(Id) -> Id)
  - l'opération inverse de désagrégation (Set(Id) <- Id)
  - l'opération de suppression qui prend un Id et un ens. d'Id (Id, Set(Id) -> )
  - l'opération inverse de création (Set(Id) -> Id)
  - l'opération de changement d'identifiant (IdAncien -> IdNouveau)
  - l'opération de transfert de territoire d'une commune à une autre (IdSource -> IdDestination)
  
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
