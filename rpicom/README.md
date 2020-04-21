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
Finalement, ils ne remplissent leur rôle de localisation.

Pour traiter cette difficulté, l'idée est de créer un nouveau référentiel appelé "référentiel pivot des codes INSEE
des Communes" (RPiCom) contenant tous les codes INSEE des communes ayant existé depuis le 1/1/1943.
A chaque code INSEE sont asssociées des informations versionnées permettant de retrouver l'état de la commune à une date
donnée.  
Ainsi les codes INSEE intégrés un jour dans une base restent valables et peuvent être utilisés par exemple pour géocoder
l'information ou pour la croiser avec un référentiel à jour des communes.

## Résultat (provisoire)
Le fichier [exrpicom.yaml](exrpicom.yaml) spécifie le schéma du référentiel ;
le champ $schema définit le schéma JSON des données et le champ contents donne un exemple de contenu.

Le fichier [rpicom.yaml](rpicom.yaml) contient le référentiel produit à partir du COG au 1/1/2020.

## Cas d'utilisation
### Localisation d'un objet à l'intérieur d'une commune
On prend le cas de localisation d'une autorisation de travaux dans une commune.
Si le code INSEE associé à l'information ne correspond plus à une commune simple alors plusieurs cas:

  - l'identifiant correspond à une commune rattachée (associée ou déléguée) -> prendre la commune de rattachement ;
  - l'identifiant correspond à un arrondissement municipal -> prendre la commune de rattachement ;
  - l'identifiant correspond à une commune fusionnée -> c. dans laquelle elle a été fusionnée.
  - l'identifiant correspond à une commune supprimée -> son territoire a été réparti dans plusieurs c. ;
    il est impossible de savoir a priori dans laquelle se situe l'objet. Prendre la première en créant un risque d'erreur.

### Association d'une information quantitative à une commune
On prend le cas d'association à une commune du nombre de permis de construire délivrés dans cette commune.

### Association d'une information qualitative à une commune
Exemple du classement d'une commune, par exemple classement d'une commune en zone vulnérable aux nitrates.
