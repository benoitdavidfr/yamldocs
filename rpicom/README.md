# Utilisation du code INSEE des communes comme référentiel pivot

L'objectif de ce projet est d'améliorer l'utilisation comme référentiel pivot du code INSEE des communes.

De nombreuses bases de données, par exemple des bases de décisions administratives, utilisent le code INSEE des communes
pour localiser leur information, dans l'exemple les décisions administratives.

Les codes INSEE des communes évoluant, ils devraient être modifiés dans la base pour tenir compte de ces évolutions.
Ces modifications ne sont généralement pas faites et les codes INSEE ainsi contenus ne peuvent plus être croisés
avec un référentiel à jour des communes par exemple pour géocoder les informations de la base.

Pour traiter cette difficulté, l'idée est de créer un nouveau référentiel appelé référentiel pivot des codes INSEE
des Communes (RPiCom) contenant tous les codes INSEE des communes ayant existé depuis le 1/1/1943.
A chaque code INSEE sont asssociées des informations versionnées qui permettent de retrouver l'état de la commune à une date
donnée.  
Ainsi les codes INSEE intégrés un jour dans une base restent valables et peuvent être utilisés par exemple pour géocoder
l'information ou pour la croiser avec un référentiel à jour des communes.

La première chose à faire est de comprendre et de reformaliser la liste des mouvements INSEE.
Pour cela, l'idée est de partir d'un état au 1/1/1943 et de dériver des mouvements un état à différentes dates.

Le fichier [conception.yaml](conception.yaml) détaille la logique suivie.

Le fichier [exfcoms.yaml](exfcoms.yaml) spécifie mon format de fichier de communes à un instant donné ;
le champ $schema définit le schéma JSON des données et le champ contents donne un exemple de contenu.

Le fichier [exevolcoms.yaml](exevolcoms.yaml) spécifie mon format de fichier d'évolutions des communes ;
de la même manière le champ $schema définit le schéma JSON et le champ contents donne un exemple de contenu.

Je pars :

  - du [fichier des communes au 1/1/1943 produit par
    Etalab](https://github.com/etalab/geohisto/blob/master/exports/communes/communes.csv)
    que j'ai traduit dans mon format en Yaml dans [com1943.yaml](com1943.yaml),
  - du fichier des communes au 1/1/2000 produit par
    l'INSEE disponible [sur le site de l'INSEE](https://www.insee.fr/fr/information/2560681)
    que j'ai [traduit dans mon format en Yaml](com2000-01-01insee.yaml),
  - du millésime 2020 de la "liste des événements survenus aux communes, arrondissements municipaux, communes associées et
    communes déléguées depuis 1943" produit par l'INSEE
    et [disponible sur](https://www.data.gouv.fr/fr/datasets/r/7c3f4702-209c-44c4-9efe-9bcef56a0ea8),

Je traduis la liste d'évènements INSEE dans mon format d'évolutions des communes 
et je génère le [fichier correspondant des communes au 1/1/2000](com2000-01-01gen.yaml).  
Enfin je compare ce dernier fichier avec celui produit par l'INSEE.
