/*PhpDoc:
name: rpigeo3.sql
title: rpigeo3.sql - constitution de la base Rpigéo par des requêtes PostGis
doc: |
  Ecriture de requêtes PostGis pour
    1) déduire les limites entre communes des communes fournies en polygone par l'IGN
    2) stocker ces limites en les partageant
      a) entre communes et entités rattachées et
      b) entre versions successives des communes
    3) définir comme vue matérialisée les communes à partir de ces limites
    4) définir une géométrie simplifiée des limites et en conséquence des communes
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
      c) les parties des c. simples rattachantes non couvertes par des e. rattachées, appelées e. complémentaires
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
    - eratcorrigee - produit dans errorcorr.sql
    - ecomp - prduit dans errorcorr.sql

  Tables peuplées en sortie:
    - eadmvlim - participation d'une limite à la description du contour d'une commune
    - lim - limite entre communes (simples ou rattachées) ou avec l'extérieur
    - eadmvpol - polygones générés à partir des limites

  Tables temporaires:
    - elt - substitution aux c. rattachantes leurs entités rattachées + complémentaires.
    - eltint - intersections entre éléments
    - eltinterror
    - eltdecrattachante
    - limcrattachante

journal: |
  20/6/2020:
    - exploit d'une nlle version de errorcorr.sql
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
  where id not in (select crat from eratcorrb)
union
  -- les e. rattachées / type vaut COMA, COMD ou ARM
  select id, type, geom from eratcorrb
union
  -- les complémentaires
  select id, 'ec' as type, geom from ecomp;
comment on table elt is 'Elément administif = c. simples non ratt. + entités rattachées + entités complémentaires';
create index elt_geom_gist on elt using gist(geom);

----------------------------------------------------------------------------------
-- 2) générer les limites entre ces éléments en calculant leur intersection 2 à 2
----------------------------------------------------------------------------------
-- 2a) calcul des intersections entre éléments en s'assurant que ces limites sont linéaires / 109 135
-- 109142 - prend 4'10" sur Mac
drop table if exists eltint;
select 'Début:', now();
create table eltint as 
select e1.id id1, e1.type typ1, e2.id id2, e2.type typ2, ST_LineMerge(ST_Intersection(e1.geom, e2.geom)) geom
from elt e1, elt e2
where e1.geom && e2.geom and e1.id < e2.id and ST_Dimension(ST_Intersection(e1.geom, e2.geom)) > 0;
select 'Fin:', now();
create index eltint_geom_gist on eltint using gist(geom);

-- 2b) liste des intersections non linéaires, fait partie des tests de pré-condition
select id1, typ1, id2, typ2, ST_Dimension(geom), ST_AsText(geom) from eltint where ST_Dimension(geom)<>1;
-- -> vide

-- 0
drop table if exists eltinterror;
create table eltinterror as
select id1, typ1, id2, typ2, numgeom, ST_GeometryN(geom, numgeom) geom
from eltint, generate_series(1,1000) numgeom
where ST_Dimension(geom)<>1 and numgeom <= ST_NumGeometries(geom);

select id1, typ1, id2, typ2, numgeom, ST_AsText(geom) from eltinterror where ST_Dimension(geom)=2;
select id1, typ1, id2, typ2, numgeom, ST_AsGeoJSON(geom) from eltinterror where ST_Dimension(geom)=2;

-- -> 0 rows OK

-------------------------------------
-- 3) Calcul des limites extérieures 
-------------------------------------
-- 3a) -> exterior3.sql -> produit la table eltextlim
-- 3b) Intégration des limites extérieures dans les limites des éléments // 1526
insert into eltint(id1, typ1, id2, typ2, geom)
  select id, type, iso3, 'ext', geom
  from eltextlim;

----------------------------------------------------------------------------------
-- 4) Peuplement du schéma rpigeo
----------------------------------------------------------------------------------
-- 4a) définition du schema -> schema.sql
-- 4b) chargement du fichier rpicom dans les tables eadminv et evtCreation

-- 4c) je remplis la table lim à partir de eltint en ajoutant un serial et en décomposant les Multi* en LineString / 111 127
truncate table eadmvlim cascade;
truncate table lim cascade;
insert into lim(geom, source)
  select geom, 'AE2020COG'
  from eltint
  where GeometryType(geom)='LINESTRING'
union
  select ST_GeometryN(geom, n), 'AE2020COG'
  from eltint, generate_series(1,100) n
  where GeometryType(geom)<>'LINESTRING'
    and n <= ST_NumGeometries(geom)
    and GeometryType(ST_GeometryN(geom, n))='LINESTRING';

-- 4d) je remplis la table eadmvlim en cherchant pour chaque code insee le numéro de limite
-- attention, je perd les limites des complémentaires qui ne sont pas des eadmimv
-- il faut retrouver le bon n-uplet dans eadminv cad en tenant compte du statut / 217 669 / 3'
select 'Début:', now();
insert into eadmvlim(cinsee, dcreation, statut, limnum)
  select cinsee, dcreation, statut, lim.num
  from eadminv, eltint cc, lim
  where (  (id1=cinsee and ((cc.typ1='cSimple' and statut='cSimple') or (cc.typ1<>'cSimple' and statut<>'cSimple')))
        or (id2=cinsee and ((cc.typ2='cSimple' and statut='cSimple') or (cc.typ2<>'cSimple' and statut<>'cSimple'))))
    and fin is null and lim.geom && cc.geom and ST_Dimension(ST_Intersection(lim.geom, cc.geom))=1;
select 'Fin:', now();


-- 4e) vérifier les polygones générés à partir des limites / 36987
-- constater que les polygones couvrent l'ens. du territoire à l'exception des ecomp
drop table if exists eadmvpol;
create table eadmvpol as
select cinsee, dcreation, statut, (ST_Dump(ST_Polygonize(geom))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation, statut;
-- -> semble correct

-- 4f) création de la géométrie généralisée
update lim set simp3=ST_SimplifyPreserveTopology(geom, 0.001);
-- génération de la table des polygones à partir des limites généralisées - 36934 - manque environ 50 du à généralisation
drop table if exists eadmvpolg3;
create table eadmvpolg3 as
select cinsee, dcreation, statut, (ST_Dump(ST_Polygonize(simp3))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation, statut;

----------------------------------------------------------------------------------
-- 5) calculer les limites des c. rattachantes et les ajouter à eadmvlim
----------------------------------------------------------------------------------

elt:
  id
  type: cSimple|COMA|COMD|ARM
  geom

eltint:
  id1
  typ1
  id2
  typ2
  geom

eratcorrb
  ogc_fid
  id - code Insee
  nom - nom
  crat - code Insee com de ratt.
  type - COMD/COMA/ARM
  geom

ecomp:
  id: concat(crat,'c')
  crat:
  npol:
  geom:
  
-- 5a) c. rattachantes et leurs éléments
create table eltdecrattachante as
  select id, crat from eratcorrb
  union
  select id, crat from ecomp;
  
-- 5b) construction des limites des c. rattachantes (13619)
-- la clause having permet de ne prendre que les limites qui apparaissent qu'une seule fois
drop table if exists limcrattachante;
create table limcrattachante as
select crat, geom
from eltdecrattachante, eltint
where id=id1 or id=id2
group by crat, geom
having count(*)=1;

-- 5c) insertion dans la table eadmvlim des limites des c. rattachantes / 13675
insert into eadmvlim(cinsee, dcreation, statut, limnum)
  select cinsee, dcreation, statut, lim.num
  from eadminv, limcrattachante lr, lim
  where cinsee=lr.crat and fin is null and statut='cSimple'
    and lim.geom && lr.geom and ST_Dimension(ST_Intersection(lim.geom, lr.geom))=1;

drop table limcrattachante;
drop table eltdecrattachante;

-- 5d) vérifier les polygones générés à partir des limites / 38084
drop table if exists eadmvpol;
create table eadmvpol as
select cinsee, dcreation, statut, (ST_Dump(ST_Polygonize(geom))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation, statut;
