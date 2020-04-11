# Utilisation du code INSEE des communes comme référentiel pivot

L'objectif de ce projet est d'outiller l'utilisation du code INSEE des communes comme référentiel pivot.  
Ces codes évoluant, lorsqu'une base les utilise pour localiser des informations, il est nécessaire de la modifier pour tenir
compte de ces évolutions.  
Pour cela je cherche à créer une liste d'évolutions des communes avec un sémantique adaptée pour effectuer
les modifications d'une base utilisant les codes INSEE des communes.

Le fichier [conception.yaml](conception.yaml) détaille la logique suivie.

Le fichier [exfcoms.yaml](exfcoms.yaml) spécifie mon format de fichier de communes à un instant donné ;
le champ $schema définit le schéma JSON des données et le champ contents donne un exemple de contenu.

Le fichier [exevolcoms.yaml](exevolcoms.yaml) spécifie mon format de fichier d'évolutions des communes ;
de la même manière le champ $schema définit le schéma JSON et le champ contents donne un exemple de contenu.

Je pars de:

  - du [fichier des communes au 1/1/1943 produit par
    Etalab](https://github.com/etalab/geohisto/blob/master/exports/communes/communes.csv)
    que j'ai traduit dans mon format en Yaml dans [com1943.yaml](com1943.yaml),
  - du fichier des communes au 1/1/2000 produit par
    l'INSEE disponible [sur le site de l'INSEE](https://www.insee.fr/fr/information/2560681)
    que j'ai [traduit dans mon format en Yaml](com2000-01-01insee.yaml),
  - du millésime 2020 de la "liste des événements survenus aux communes, arrondissements municipaux, communes associées et communes déléguées depuis 1943" prduit par l'INSEE et disponible
    sur [https://www.data.gouv.fr/](https://www.data.gouv.fr/fr/datasets/r/7c3f4702-209c-44c4-9efe-9bcef56a0ea8),

Je traduis la liste d'évènements INSEE dans mon format d'évolutions des communes 
et je génère le [fichier correspondant des communes au 1/1/2000](com2000-01-01gen.yaml).  
Enfin je compare ce dernier fichier avec com2000-01-01insee.yaml.
