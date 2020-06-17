-- extrait de rpigeo2.sql
-------------------------------------
--         Test sur la Corse
-------------------------------------

-- sélection des communes de Corse (360)
drop table if exists com20;
create table com20 as
select id, nom_com, wkb_geometry geom
from commune_carto
where id like '2A%' or id like '2B%';

-- tables des limites entre communes de Corse
-- ST_Dimension()=1 supprime les points et les GeometryCollection vides
-- les lignes sont structurées comme ensemble de segments (MultiLineString)
drop table comcom20;
create table comcom20 as 
select c1.id id1, c2.id id2, ST_Intersection(c1.geom, c2.geom) geom
from com20 c1, com20 c2
where c1.geom && c2.geom and c1.id < c2.id and ST_Dimension(ST_Intersection(c1.geom, c2.geom))=1
union
select id id1, '0exterior' id2, ST_Intersection(c.geom, e.geom) geom
from com20 c, exterior e
where iso3='FXX' and ST_Dimension(ST_Intersection(c.geom, e.geom))=1;

-- remplace les MultiLineString par des LineString
update comcom20 set geom=ST_LineMerge(geom);

-- visu du résultat
select id1, id2, ST_AsText(geom) from comcom20;

-- regénération des polygones à partir des limites
-- lorsqu'une commune contient un trou, 2 polygones sont générés au lieu d'un seul avec un trou
drop table com20pol;
create table com20pol as
select id, cinsee, dcreation, (ST_Dump(ST_Polygonize(geom))).geom as geom
from eadminv, comcom20
where id1 = cinsee or id2 = cinsee
group by cinsee, dcreation;

-- exemple de commune avec un trou
select cinsee, ST_AsText(geom) from com20pol where cinsee='2B049';

-- la table lim est remplie à partir de comcom20 en ajoutant un identifiant et en décomposant les MultiLineString en LineString
-- je perds l'information sur 
insert into lim(geom, source)
  select geom, 'AE2020COG'
  from comcom20
  where GeometryType(geom)='LINESTRING'
union
  select ST_GeometryN(geom, n), 'AE2020COG'
  from comcom20, generate_series(1,100) n
  where GeometryType(geom)<>'LINESTRING'
    and n <= ST_NumGeometries(geom);

-- je remplis la table eadmvlim en cherchant pour chaque codeinsee le numéro de limite
insert into eadmvlim(cinsee, dcreation, limnum)
  select cinsee, dcreation, num
  from eadminv, comcom20 cc, lim
  where (id1=cinsee or id2=cinsee) and fin is null and crat is null
    and ST_Dimension(ST_Intersection(lim.geom, cc.geom))=1;

-- vérifier que l'on sait regénérer les communes à partir
-- c'est globalement satisfaisant mais qqs cas particuliers génèrent des anomalies
-- 362 / 360
drop table if exists eadmvpol;
create table eadmvpol as
select cinsee, dcreation, (ST_Dump(ST_Polygonize(geom))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation;

-- création de la géométrie généralisée
update lim set simp3=ST_SimplifyPreserveTopology(geom, 0.001);
-- la simplification d'une limite génère une erreur topologique dans le polygone d'une commune
update lim set simp3=geom where num=902;
-- génération de la table des polygones à partir des limites généralisées
drop table if exists eadmvpolg3;
create table eadmvpolg3 as
select cinsee, dcreation, (ST_Dump(ST_Polygonize(simp3))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation;

--
-- entités rattachées
--

-- aucune entité rattachée en Corse
-- il faut donc travailler sur un autre département pour mettre au point le code pour les taiter
select * from eadminv
where cinsee like '17%'
  and crat is not null;
