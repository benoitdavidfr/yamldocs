# Utilisation du code INSEE des communes comme référentiel pivot

L'objectif de ce projet est d'outiller l'utilisation du code INSEE des communes comme référentiel pivot.  
Ces codes évoluant, lorsqu'une base les utilise pour localiser des informations, il est nécessaire de la modifier pour tenir
compte de ces évolutions.  
Pour cela je cherche à créer une liste d'évolutions des communes avec un sémantique adaptée pour effectuer
les modifications d'une base utilisant les codes INSEE des communes.

Le fichier [conception.yaml](conception.yaml) détaille la logique suivie.

Le fichier [exfcoms.yaml](exfcoms.yaml) d'une part spécifie mon format de fichier de communes à un instant donné ;
le champ $schema définit le schéma JSON des données et le champ contents donne un exemple de contenu.


