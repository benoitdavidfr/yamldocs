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
  L'étape préliminaire consiste à vérifier les données IGN et éventuellement les corriger.

  L'algorithme est ensuite le suivant:
    1) fabriquer une couche d'éléments (elt) correspondant à un pavage du territoire, cad ne s'intersectant pas 2 à 2
      et dont l'union couvre l'ensemble du territoire, constituée de:
      a) les c. simples non rattachantes
      b) les e. rattachées
      c) les parties des c. simples non rattachantes non couvertes par des e. rattachées, appelées e. complémentaires
      Les éléments correspondent aux faces du graphe topologique administratif fusionnant les communes simples et les e. rattachées.
    2) générer les limites entre ces éléments en calculant leur intersection 2 à 2
    3) générer les limites extérieures des éléments - voir exterior.sql
    4) peupler lim et eadmvlim à partir des 2 ens. de limites précédemment constitués
    5) calculer les limites des c. rattachantes et les ajouter à eadmvlim

  Pour que le traitement ne génère pas d'erreurs, il faut définir les pré-conditions et les tester.
  En l'espèce, la pré-condition est que les éléments + l'extérieur constituent un pavage de l'univers.
  Cela peut se traduire par:
    1) vérifier que 2 éléments ne s'intersectent pas
    2) construire l'extérieur à partir des éléments
journal: |
  13/6/2020
    - suite de rpigeo2.sql
    - utilise schema.sql
*/

---------------------------------------
-- 1) fabriquer la couche des éléments
---------------------------------------
-- 1a) créer les entités complémentaires (ecomp) - somme des entités rattachées groupées par rattachante
-- 1a.i) somme des rattachées (1076)
drop table if exists srattache;
create table srattache as
  select insee_ratt as cinsee, ST_Union(wkb_geometry) as geom
  from entite_rattachee_carto
  group by insee_ratt;

-- calcul des entités complémentaires éventuelles (416)
-- l'id est le code INSEE concaténé avec 'c', cela évite qu'une entité complémentaire porte un code INSEE existant
drop table if exists ecomp;
create table ecomp as
  select concat(c.id, 'c') id, c.id insee_ratt, ST_Difference(c.wkb_geometry, sr.geom) geom
  from commune_carto c, srattache sr
  where c.id=sr.cinsee and ST_Dimension(ST_Difference(c.wkb_geometry, sr.geom))=2;

-- affichage
select id, ST_Dimension(geom), ST_AsText(geom) from ecomp;

-- 1b) fabrication de la couche des éléments en substituant aux c. rattachantes leurs entités rattachées + complémentaires.
-- + l'extérieur (37239)
-- schema: id, type, geom
drop table if exists elt;
create table elt as
  -- les c. s. non rattachantes
  select id, 'cSimple' as type, wkb_geometry as geom
  from commune_carto
  where id not in (select insee_ratt from entite_rattachee_carto)
union
  -- les e. rattachées / type vaut COMA, COMD ou ARM
  select id, type, wkb_geometry as geom from entite_rattachee_carto
union
  -- les complémentaires
  select id, 'ec' as type, geom from ecomp;
create index elt_geom_gist on elt using gist(geom);

----------------------------------------------------------------------------------
-- 2) générer les limites entre ces éléments en calculant leur intersection 2 à 2
----------------------------------------------------------------------------------
-- 2a) calcul des intersections entre éléments en supprimant les intersections vides ou réduites à un point
-- 109142 - prend 4'10" sur Mac
drop table if exists eltelt;
select 'Début:', now();
create table eltelt as 
select e1.id id1, e1.type typ1, e2.id id2, e2.type typ2, ST_Intersection(e1.geom, e2.geom) geom
from elt e1, elt e2
where e1.geom && e2.geom and e1.id < e2.id and ST_Dimension(ST_Intersection(e1.geom, e2.geom)) > 0;
select 'Fin:', now();
create index eltelt_geom_gist on eltelt using gist(geom);

-- 2b) liste des intersections non linéaires, fait partie des tests de pré-condition
-- 16 tuples générés
select id1, typ1, id2, typ2, ST_Dimension(geom), ST_AsText(geom) from eltelt where ST_Dimension(geom)=1;

-- 465
drop table if exists eltelterror;
create table eltelterror as
select id1, typ1, id2, typ2, numgeom, ST_GeometryN(geom, numgeom) geom
from eltelt, generate_series(1,1000) numgeom
where ST_Dimension(geom)<>1 and numgeom < ST_NumGeometries(geom);

select id1, typ1, id2, typ2, numgeom, ST_AsText(geom) from eltelterror where ST_Dimension(geom)=2;


-- corrections dues aux bugs d'incohérence topologique sur les données IGN
-- erreur d'incohérence entre 08079 (COMD de 08173) et 08400
-- solution: 08079 = (08079 + 08173c) - 08400
update elt
  set geom=(select ST_Union(e1.geom, e2.geom) from elt e1, elt e2 where e1.id='08079' and e2.id='08173c')
  where id='08079';
update elt
  set geom=(select ST_Difference(e1.geom, e2.geom) from elt e1, elt e2 where e1.id='08079' and e2.id='08400')
  where id='08079';
delete from elt where id='08173c';

-- erreur d'incohérence entre 52064 (COMD de 52064) et 52455
-- 52064 = (52064 + 52064c) - 52455
update elt
  set geom=(select ST_Union(e1.geom, e2.geom) from elt e1, elt e2 where e1.id='52064' and e2.id='52064c')
  where id='52064';
update elt
  set geom=(select ST_Difference(e1.geom, e2.geom) from elt e1, elt e2 where e1.id='52064' and e2.id='52455')
  where id='52064';
delete from elt where id='52064c';

-- 52054 = (52054 + 52008c) - 52107
update elt
  set geom=(select ST_Union(e1.geom, e2.geom) from elt e1, elt e2 where e1.id='52054' and e2.id='52008c')
  where id='52054';
update elt
  set geom=(select ST_Difference(e1.geom, e2.geom) from elt e1, elt e2 where e1.id='52054' and e2.id='52107')
  where id='52054';
delete from elt where id='52008c';

-- 43255 = 43255 - 43245
update elt
  set geom=(select ST_Difference(e1.geom, e2.geom) from elt e1, elt e2 where e1.id='43255' and e2.id='43245')
  where id='43255';

Puis construire l'extérieur à partir des elts pour assurer la cohérence

