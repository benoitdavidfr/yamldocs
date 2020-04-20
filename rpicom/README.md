# Utilisation du code INSEE des communes comme référentiel pivot

## Introduction
L'objectif de ce projet est d'améliorer l'utilisation comme référentiel pivot du code INSEE des communes.

De nombreuses bases de données, par exemple des bases de décisions administratives, utilisent le code INSEE des communes
pour localiser leur contenu, par exemple les décisions administratives.

Les codes INSEE des communes évoluant, ils devraient être modifiés dans la base pour en tenir compte.
Ces modifications ne sont généralement pas faites et les codes INSEE ainsi contenus ne peuvent plus être croisés
avec un référentiel à jour des communes par exemple pour géocoder les informations de la base.

Pour traiter cette difficulté, l'idée est de créer un nouveau référentiel appelé "référentiel pivot des codes INSEE
des Communes" (RPiCom) contenant tous les codes INSEE des communes ayant existé depuis le 1/1/1943.
A chaque code INSEE sont asssociées des informations versionnées qui permettent de retrouver l'état de la commune à une date
donnée.  
Ainsi les codes INSEE intégrés un jour dans une base restent valables et peuvent être utilisés par exemple pour géocoder
l'information ou pour la croiser avec un référentiel à jour des communes.

## Résultat (provisoire)
Le fichier [exrpicom.yaml](exrpicom.yaml) spécifie le schéma du référentiel ;
le champ $schema définit le schéma JSON des données et le champ contents donne un exemple de contenu.

Le fichier [rpicom.yaml](rpicom.yaml) contient le référentiel produit à partir du COG au 1/1/2020.

## Utilisation
### Localisation d'une information à l'intérieur d'une commune
Par exemple, localisation d'une autorisation de travaux dans une commune.
Si le code INSEE associé à l'information ne correspond plus à une commune simple alors plusieurs cas:

  - l'identifiant correspond à une commune associée -> prendre la commune de rattachement à laquelle elle a été associée ;
  - l'identifiant correspond à une commune déléguée -> prendre la commune de rattachement dont elle est déléguée ;
  - l'identifiant correspond à un arrondissement municipal -> prendre la commune de rattachement ;
  - l'identifiant correspond à une commune périmée -> ???.

### Identification d'une commune