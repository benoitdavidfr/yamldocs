-- extrait de rpigeo2.sql correspondant au test réussi sur D17 - 13/6/2020 1:00

-------------------------------------
--         Test sur dept 17
-------------------------------------

/* Algorithme (10/6/2020)
1) Si une commune est hétérogène avec des entités rattachées et des parties non couvertes,
   alors créer une pseudo-entité rattachée, appelée complémentaire
   fin_si
2) Avant de créer les limites entre communes, substituer aux communes ayant des entités rattachées (ie c. rattachantes)
   leurs entités rattachées + complémentaires.
3) Fabriquer les limites comcom17m
4) générer les eadmvlim
5) calculer les limites des c. rattachantes et les ajouter à eadmvlim
*/

-- création d'un extérieur spécifique au D17 pour que la génération des limites traite les limites avec d'autres depts
drop table if exists exterior;
create table exterior as
  select num, iso3, ST_Difference(box, geom) as geom
  from univers, comunionpardept
  where iso3='FXX' and dept='17';

-- communes du 17 (463)
drop table if exists com17;
create table com17 as
select id, wkb_geometry geom
from commune_carto
where id like '17%';

-- entités rattachées du 17 (16)
drop table if exists erat17;
create table erat17 as
select id, insee_ratt, type, wkb_geometry geom
from entite_rattachee_carto
where id like '17%';

-- exemples entités rattachées
-- 17240 a 2 COMA + une partie hors COMA
-- 17219 est décomposée en 2 COMD sans partie hors COMD

-- 1) créer les entités complémentaires (ecomp)
-- somme des entités rattachées groupées par rattachante
drop table if exists srattache;
create table srattache as
  select insee_ratt as cinsee, ST_Union(geom) as geom
  from erat17
  group by insee_ratt;

-- calcul des entités complémentaires éventuelles
-- l'id est le code INSEE concaténé avec 'c'
drop table if exists ecomp;
create table ecomp as
  select concat(c.id, 'c') id, c.id insee_ratt, ST_Difference(c.geom, sr.geom) geom
  from com17 c, srattache sr
  where c.id=sr.cinsee and ST_Dimension(ST_Difference(c.geom, sr.geom))=2;

-- affichage
select id, ST_Dimension(geom), ST_AsText(geom) from ecomp;

-- 2) fabrication d'un com17 modifié en substituant aux c. rattachantes leurs entités rattachées + complémentaires.
-- + l'extérieur
drop table if exists com17m;
create table com17m as
  -- les c. s. non rattachantes
  select id, 'cSimple' as type, geom
  from com17
  where id not in (select insee_ratt from erat17)
union
  -- les e. rattachées / type vaut COMA, COMD ou ARM
  select id, type, geom from erat17
union
  -- les complémentaires
  select id, 'ec' as type, geom from ecomp
union
  select iso3, 'ext' as type, geom from exterior;

-- 3) tables des limites entre communes + e. ratt. + e. comp. de D17 + extérieur
-- ST_Dimension()=1 supprime les points et les GeometryCollection vides
-- ST_Intersection() génère des lignes structurées comme ensemble de segments (MultiLineString)
-- ST_LineMerge() reconstruit des LineString
drop table if exists comcom17m;
create table comcom17m as 
select c1.id id1, c1.type typ1, c2.id id2, c2.type typ2, ST_LineMerge(ST_Intersection(c1.geom, c2.geom)) geom
from com17m c1, com17m c2
where c1.geom && c2.geom and c1.id < c2.id and ST_Dimension(ST_Intersection(c1.geom, c2.geom))=1;

-- je remplis la table lim à partir de comcom17m en ajoutant un serial et en décomposant les MultiLineString en LineString
truncate table eadmvlim cascade;
truncate table lim cascade;
insert into lim(geom, source)
  select geom, 'AE2020COG'
  from comcom17m
  where GeometryType(geom)='LINESTRING'
union
  select ST_GeometryN(geom, n), 'AE2020COG'
  from comcom17m, generate_series(1,100) n
  where GeometryType(geom)<>'LINESTRING'
    and n <= ST_NumGeometries(geom);

-- 4) je remplis la table eadmvlim en cherchant pour chaque code insee le numéro de limite
-- attention, je perd les limites des complémentaires qui ne sont pas des eadmimv
-- il faut retrouver le bon n-uplet dans eadminv cad en tenant compte du statut
insert into eadmvlim(cinsee, dcreation, statut, limnum)
  select cinsee, dcreation, statut, lim.num
  from eadminv, comcom17m cc, lim
  where (  (id1=cinsee and ((cc.typ1='cSimple' and statut='cSimple') or (cc.typ1<>'cSimple' and statut<>'cSimple')))
        or (id2=cinsee and ((cc.typ2='cSimple' and statut='cSimple') or (cc.typ2<>'cSimple' and statut<>'cSimple'))))
    and fin is null and ST_Dimension(ST_Intersection(lim.geom, cc.geom))=1;

-- 4bis) vérifier les polygones générés à parir des limites
-- constat que les polygones couvrent l'ens. du département à l'exception des ecomp
drop table if exists eadmvpol;
create table eadmvpol as
select cinsee, dcreation, statut, (ST_Dump(ST_Polygonize(geom))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation, statut;

-- 5) calculer les limites des c. rattachantes et les ajouter à eadmvlim
-- construction de la table des erat et comp (19)
drop table if exists erat17m;
create table erat17m as
  select id, insee_ratt, geom from erat17
union
  select id, insee_ratt, geom from ecomp;

-- construction des limites des c. rattachantes (72)
-- je ne prend que les limites qui apparaissent qu'une seule fois
drop table if exists limrattachante;
create table limrattachante as
select insee_ratt, cc.geom
from comcom17m cc, erat17m er
where er.id=cc.id1 or er.id=cc.id2
group by insee_ratt, cc.geom
having count(*)=1;

-- insertion dans la table eadmvlim des limites des c. rattachantes
insert into eadmvlim(cinsee, dcreation, statut, limnum)
  select cinsee, dcreation, statut, lim.num
  from eadminv, limrattachante lr, lim
  where cinsee=insee_ratt and fin is null and statut='cSimple' and ST_Dimension(ST_Intersection(lim.geom, lr.geom))=1;

-- vérifier que l'on sait regénérer les communes à partir (480)
-- Royan qui correspond à 2 polygones correspond à 2 n-uplets
drop table if exists eadmvpol;
create table eadmvpol as
select cinsee, dcreation, statut, (ST_Dump(ST_Polygonize(geom))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation, statut;


select * from eadminv where cinsee='17219'

-- Cas de Royan avec une ile en plus
-- -> génère 2 n-uplets avec chacun un polygone
select cinsee, dcreation, ST_AsText(geom)
from eadmvpol
where cinsee='17306';

-- création de la géométrie généralisée
update lim set simp3=ST_SimplifyPreserveTopology(geom, 0.001);
-- génération de la table des polygones à partir des limites généralisées
drop table if exists eadmvpolg3;
create table eadmvpolg3 as
select cinsee, dcreation, statut, (ST_Dump(ST_Polygonize(simp3))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation, statut;
