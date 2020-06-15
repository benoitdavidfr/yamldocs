/*PhpDoc:
name: rpigeo3.sql
title: rpigeo3.sql - constitution de la base Rpigéo par des requêtes PostGis
doc: |
  Ecriture de requêtes PostGis pour
    1) déduire les limites entre communes des communes fournies en polygone par l'IGN
    2) stocker ces limites en les partageant
      d'une part entre communes et entités rattachées et
      d'autre part entre versions successives des communes
    3) définir comme vue matérialisée les communes à partir de ces limites
    4) définir une géométrie simplifiée des limites et des communes
  Dans cette version je gère les bugs IGN.
  Ce script utilise le schema cible de rpigéo défini dans schema.sql

  Dans un premier temps on constitue les données initiales à partir des couches commune_carto et entite_rattachee_carto de AE2020COG
  corrigées dans ~/html/data/aegeofla/makefra.php des bugs initiaux détectées
  L'étape préliminaire consiste à vérifier les données IGN et éventuellement les corriger dans errorcorr.sql.

  L'algorithme est ensuite le suivant:
    1) fabriquer une couche d'éléments (elt) correspondant à un pavage du territoire, cad ne s'intersectant pas 2 à 2
      et dont l'union couvre l'ensemble du territoire, constituée de:
      a) les c. simples non rattachantes
      b) les e. rattachées
      c) les parties des c. simples non rattachantes non couvertes par des e. rattachées, appelées e. complémentaires
      Les éléments correspondent aux faces du graphe topologique administratif fusionnant les communes simples et les e. rattachées.
    2) générer les limites entre ces éléments en calculant leur intersection 2 à 2
    3) générer les limites extérieures des éléments - voir exterior3.sql
    4) peupler lim et eadmvlim à partir des 2 ens. de limites précédemment constitués
    5) calculer les limites des c. rattachantes et les ajouter à eadmvlim

  Pour que le traitement ne génère pas d'erreurs, il faut définir les pré-conditions et les tester.
  En l'espèce, la pré-condition est que les éléments + l'extérieur constituent un pavage de l'univers.
  Le test est effectué dans errorcorr.sql qui génère plusieurs tables réutilisées par la suite

  Tables en entrée:
    - commune_carto
    - eratcorrigee - prduit dans errorcorr.sql
    - ecomp - prduit dans errorcorr.sql
journal: |
  15/6/2020
    8:50
      - reste au moins 3 erreurs dans la construction des limites des elts
        Erreurs:
         - 49013 COMD de 49228
         - 52054 COMD de 52008
         - 65116 COMS
      - reprendre le code rpigeo2.sql pour la suite
  14/6/2020
    - écriture de errorcorr.sql qui teste la pré-condition et effectue des corrections nécessaires
    - écriture de errorcorrsup.sql qui rédéfinit qqs éléments qui posent problèmes et que je ne sais pas corriger autrement
  13/6/2020
    - suite de rpigeo2.sql
    - utilise schema.sql
*/

----------------------------------------------------------------------------------
-- 0) tester les pré-conditions et corriger les données en entrée -> errorcor.sql
----------------------------------------------------------------------------------

---------------------------------------
-- 1) fabriquer la table des éléments
---------------------------------------
-- 1) fabrication de la table des éléments en substituant aux c. rattachantes leurs entités rattachées + complémentaires.
-- schema: id, type, geom
drop table if exists elt;
create table elt as
  -- les c. s. non rattachantes
  select id, 'cSimple' as type, wkb_geometry as geom
  from commune_carto
  where id not in (select crat from eratcorrigee)
union
  -- les e. rattachées / type vaut COMA, COMD ou ARM
  select id, type, geom from eratcorrigee
union
  -- les complémentaires
  select id, 'ec' as type, geom from ecomp;
comment on table elt is 'Elément administif = c. simples non ratt. + entités rattachées + entités complémentaires';
create index elt_geom_gist on elt using gist(geom);

----------------------------------------------------------------------------------
-- 2) générer les limites entre ces éléments en calculant leur intersection 2 à 2
----------------------------------------------------------------------------------
-- 2a) calcul des intersections entre éléments en supprimant les intersections vides ou réduites à un point
-- 109142 - prend 4'10" sur Mac
drop table if exists eltint;
select 'Début:', now();
create table eltint as 
select e1.id id1, e1.type typ1, e2.id id2, e2.type typ2, ST_Intersection(e1.geom, e2.geom) geom
from elt e1, elt e2
where e1.geom && e2.geom and e1.id < e2.id and ST_Dimension(ST_Intersection(e1.geom, e2.geom)) > 0;
select 'Fin:', now();
create index eltint_geom_gist on eltint using gist(geom);

-- 2b) liste des intersections non linéaires, fait partie des tests de pré-condition
select id1, typ1, id2, typ2, ST_Dimension(geom), ST_AsText(geom) from eltint where ST_Dimension(geom)<>1;

-- 243
drop table if exists eltinterror;
create table eltinterror as
select id1, typ1, id2, typ2, numgeom, ST_GeometryN(geom, numgeom) geom
from eltint, generate_series(1,1000) numgeom
where ST_Dimension(geom)<>1 and numgeom <= ST_NumGeometries(geom);

select id1, typ1, id2, typ2, numgeom, ST_AsText(geom) from eltinterror where ST_Dimension(geom)=2;
select id1, typ1, id2, typ2, numgeom, ST_AsGeoJSON(geom) from eltinterror where ST_Dimension(geom)=2;

-- vide

------------------------------------------------------
-- 3) générer les limites extérieures -> exterior3.sql
------------------------------------------------------

Analyse visuelle pour vérifier que eltint + eltextlim contiennent les limites de commune_carteo et eratcorrigee

Erreurs:
 - 49013 COMD de 49228
 - 52054 COMD de 52008
 - 65116 COMS


