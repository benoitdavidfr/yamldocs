/*PhpDoc:
name: errorcorr.sql
title: errorcorr.sql - vérifie la cohérence topologique et corrige les erreurs topologiques dans les données IGN en entrée
doc: |
  Ce script
    - vérifie que les commune_carto sont cohérentes topologiquement, cad
      - leur intersection 2 à 2 correspond à des lignes ou des points
      - leur union couvre le territoire,
        - pour cela je vérifie que seul FXX a une enclave dans son territoire, à savoir l'enclave espagnole de Llivia
    - produit une couche corrigée des entités rattachées qui NE sont PAS cohérentes topologiquement avec commune_carto
      - je vérifie que les entités rattachées ne s'intersectent pas entre elles
      - je limite chaque entité rattachée à sa commune rattachante,
      - je calcule les entités complémentaires
      - j'identifie parmi elles celles qui sont des sliver (8) que j'agrège alors avec une entité voisine, pour cela
        - je vérifie individuellement dans QGis les plus petites
  Stratégie:
    - je pars du principe que les communes simples sont correctes et je fonde donc sur elles la topologie
    - je corrige les entités rattachées pour:
      - les mettre chacune en cohérence topologique avec leur commune de rattachement
      - les mettre en cohérence entre elles
      - 
  Tables en entrée:
    - commune_carto (AE2020COG)
    - entite_rattachee_carto (AE2020COG)
  Tables en sortie:
    - eratcorrb - Entités rattachées corrigées
    - ecomp - Entités complémentaires, cad complément éventuel des entités rattachées dans les c. rattachantes
    - univers - Un rectangle englobant par zone géographique (FXX et chaque DOM)
    - comunionfxxdom - Union géométrique des communes, 1 pour FXX et 1 pour les DOM
    - exterior - Extérieur pour chaque zone géo., cad Polygone/MultiPolygone correspondant à la mer et l'étranger
    - extfxx - Extérieur de FXX décomposé en 2 polygones, 1 pour l'extérieur et l'autre pour l'enclave espagnole de Llivia
  Tables temporaires:
    - eratcorrigee - Entités rattachées corrigées
    - comint - intersection entre 2 commune_carto
    - erint - intersectio entre 2 entite_rattachee_carto
    - srattache - union géométrique des entités rattachées groupées par rattachante
journal: |
  20/6/2020:
    - génération de eratcorrb pour laquelle il n'y a plus d'ecomp correspondant à des slivers
  14/6/2020
    - première version
*/

--------------------------------
-- 1) Vérification de commune_carto
--------------------------------
-- 1a) Vérification de la validité des géométries
select id from commune_carto where not ST_IsValid(wkb_geometry);

-- 1a) vérifier que les commune_carto ne s'intersectent que comme ligne ou point
drop table comint if exists;
create table comint as
select c1.id id1, c2.id id2, ST_Intersection(c1.wkb_geometry, c2.wkb_geometry) geom
from commune_carto c1, commune_carto c2
where c1.id < c2.id and c1.wkb_geometry && c2.wkb_geometry and ST_Intersects(c1.wkb_geometry, c2.wkb_geometry);
comment on table comint is 'Intersections entre commune_carto 2 à 2';

-- l'intersection entre 2 communes est soit un POINT, une LINESTRING, une MULTILINESTRING ou une GEOMETRYCOLLECTION
select id1, id2, GeometryType(geom)
from comint
where GeometryType(geom)<>'MULTILINESTRING'
  and GeometryType(geom)<>'LINESTRING'
  and GeometryType(geom)<>'POINT'
  and GeometryType(geom)<>'GEOMETRYCOLLECTION';
-- -> vide <=> OK

-- si c'est une GEOMETRYCOLLECTION alors elle n'est composée que de POINT, LINESTRING ou MULTILINESTRING
select id1, id2, numgeom, ST_GeometryN(geom, numgeom) geom
from comint, generate_series(1,100) numgeom
where GeometryType(geom)='GEOMETRYCOLLECTION'
  and numgeom <= ST_NumGeometries(geom)
  and GeometryType(ST_GeometryN(geom, numgeom))<>'MULTILINESTRING'
  and GeometryType(ST_GeometryN(geom, numgeom))<>'LINESTRING'
  and GeometryType(ST_GeometryN(geom, numgeom))<>'POINT';
-- -> vide <=> OK
drop table comint;

-- 1b) vérifier que les extérieurs sont constitués pour les DOM d'un seul polygone (pas d'enclave)
-- et pour la métropole d'un extérieur et du polygone correspondant à l'enclave de Llivia

-- Union des communes en 1 tuple pour la métropole et 1 pour les DOM
create table comunionfxxdom as
  select 'FXX' as id, ST_Union(wkb_geometry) as geom
  from commune_carto
  where substring(id, 1, 2)<>'97'
union
  select 'DOM' as id, ST_Union(wkb_geometry) as geom
  from commune_carto
  where substring(id, 1, 2)='97';
comment on table comunionfxxdom is 'Union géométrique des communes, 1 pour FXX et 1 pour les DOM';

-- Un rectangle englobant par grande zone géographique
drop table if exists univers;
create table univers(
  num serial,
  iso3 char(3), -- code ISO 3166-1 alpha 3
  box geometry(POLYGON, 4326)
);
comment on table univers is 'Un rectangle englobant par grande zone géographique';
insert into univers(iso3, box) values
('FXX', ST_MakeEnvelope(-6, 41, 10, 52, 4326)),
('GLP', ST_MakeEnvelope(-62, 15.8, -61, 16.6, 4326)),
('MTQ', ST_MakeEnvelope(-61.3, 14.3, -60.8, 15, 4326)),
('GUF', ST_MakeEnvelope(-55, 2, -51, 6, 4326)),
('REU', ST_MakeEnvelope(55, -22, 56, -20, 4326)),
('MYT', ST_MakeEnvelope(44, -14, 46, -12, 4326));

-- L'extérieur des communes pour chaque gde zone géo.,
-- permettra de créer la limite extérieure cad la limite du territoire avec la mer ou l'étranger
-- je distingue FXX et DOM pour corriger les erreurs et optimiser le traitement
drop table if exists exterior;
create table exterior as
  select num, iso3, ST_Difference(box, geom) as geom
  from univers, comunionfxxdom
  where iso3='FXX' and id='FXX'
union
  select num, iso3, ST_Difference(box, geom) as geom
  from univers, comunionfxxdom
  where iso3<>'FXX' and id='DOM';
comment on table exterior is 'Extérieur pour chaque gde zone géo., cad Polygone/MultiPolygone correspondant à la mer et l''étranger.';

select iso3, ST_AsText(geom) from exterior;

drop table if exists extfxx;
create table extfxx as
  select npol, ST_GeometryN(geom, npol) as geom
  from exterior, generate_series(1,100) npol
  where iso3='FXX' and npol <= ST_NumGeometries(geom);
-- contient 2 polygones, le 1er l'extérieur et le 2nd l'enclave espagnole de Llivia
comment on table extfxx is 'Extérieur de FXX en 2 polygones, 1 pour l''extérieur et l''autre pour l''enclave espagnole de Llivia';

-- le fait que l'extérieur de chaque DOM corresponde à 1 polygone
-- et que l'extérieur de FXX corresponde à 2 polygones valide la cohérence la couverture de commune_carto

----------------------------------------
-- 2) Correction des entités rattachées
----------------------------------------
-- 2a) Vérification de la validité des géométries
select id from entite_rattachee_carto where not ST_IsValid(wkb_geometry);

-- 2a) vérifier que les entités rattachées ne s'intersectent que comme ligne ou point
create table erint as
select c1.id id1, c2.id id2, ST_Intersection(c1.wkb_geometry, c2.wkb_geometry) geom
from entite_rattachee_carto c1, entite_rattachee_carto c2
where c1.id < c2.id and c1.wkb_geometry && c2.wkb_geometry and ST_Intersects(c1.wkb_geometry, c2.wkb_geometry);

-- l'intersection entre 2 er est soit un POINT, une LINESTRING, une MULTILINESTRING ou une GEOMETRYCOLLECTION
select id1, id2, GeometryType(geom)
from erint
where GeometryType(geom)<>'MULTILINESTRING'
  and GeometryType(geom)<>'LINESTRING'
  and GeometryType(geom)<>'POINT'
  and GeometryType(geom)<>'GEOMETRYCOLLECTION';
-- -> vide OK

-- si c'est une GEOMETRYCOLLECTION alors elle n'est composée que de POINT, LINESTRING ou MULTILINESTRING
select id1, id2, numgeom, ST_GeometryN(geom, numgeom) geom
from erint, generate_series(1,100) numgeom
where GeometryType(geom)='GEOMETRYCOLLECTION'
  and numgeom <= ST_NumGeometries(geom)
  and GeometryType(ST_GeometryN(geom, numgeom))<>'MULTILINESTRING'
  and GeometryType(ST_GeometryN(geom, numgeom))<>'LINESTRING'
  and GeometryType(ST_GeometryN(geom, numgeom))<>'POINT';
-- -> vide OK
-- ca ne sert à rien de garder cette table puisque je corrige les entités rattachées
drop table erint;

-- 2b) chaque er doit être strictement incluse dans sa c. rattachante
-- je contate que ce n'est pas le cas par la requête suivante qui liste les erreurs
select er.id, ST_AsText(ST_Difference(er.wkb_geometry, c.wkb_geometry))
from entite_rattachee_carto er, commune_carto c
where er.insee_ratt=c.id
  and not ST_IsEmpty(ST_Difference(er.wkb_geometry, c.wkb_geometry));
-- -> liste les erreurs

-- 2c) en conséquence je construis la table des entités rattachées corrigées en limitant chaque er à sa rattachante
drop table if exists eratcorrigee;
create table eratcorrigee as
select er.ogc_fid, er.id, er.nom_com as nom, er.insee_ratt as crat, er.type, ST_Intersection(er.wkb_geometry, c.wkb_geometry) as geom
from entite_rattachee_carto er, commune_carto c
where er.insee_ratt=c.id;
comment on table eratcorrigee is 'Entités rattachées corrigées';
create index eratcorrigee_geom_gist on eratcorrigee using gist(geom);

eratcorrigee
  ogc_fid
  id - code Insee
  nom - nom
  crat - code Insee com de ratt.
  type - COMD/COMA/ARM
  geom
  
-- 3) je crée les entités complémentaires (ecomp)
-- 3a) somme des entités rattachées groupées par rattachante
drop table if exists srattache;
create table srattache as
  select crat as id, ST_Union(geom) as geom
  from eratcorrigee
  group by crat;

-- 3b) calcul des entités complémentaires éventuelles (416)
-- l'id est le code INSEE concaténé avec 'c'
drop table if exists ecomp;
create table ecomp as
  select concat(c.id, 'c') id, c.id crat, 0 npol, ST_Difference(c.wkb_geometry, sr.geom) geom
  from commune_carto c, srattache sr
  where c.id=sr.id and not ST_IsEmpty(ST_Difference(c.wkb_geometry, sr.geom));
comment on table ecomp is 'Entités complémentaires, cad complément éventuel des entités rattachées dans leur c. de rattachement';
drop table srattache;

-- décomposition des ecomp MULTIPOLYGON (npol > 0)
insert into ecomp
select id, crat, npol2, ST_GeometryN(geom, npol2) geom
from ecomp, generate_series(1,1000) npol2
where GeometryType(geom)='MULTIPOLYGON' and npol2 <= ST_NumGeometries(geom);

delete from ecomp where GeometryType(geom)='MULTIPOLYGON' and npol=0;

select id, crat, npol, ST_AsText(geom) from ecomppol;

-- calcul de leur surface en km2 et affichage des plus petites pour détecter les slivers
select id, crat, npol, ST_Area(geom)*40000*40000/360/360 as areaKm2
from ecomp
order by ST_Area(geom)*40000*40000/360/360; 

-- transfert des slivers dans eratcorrigée (22)
insert into eratcorrigee
  select 0, id, '', crat, 'ECOMP', geom from ecomp where ST_Area(geom)*40000*40000/360/360 < 0.165;

/* Traitement interactif avec QGis pour supprimer les slivers
  Les 22 slivers définis ci-dessus sont copiés dans eratcorrigée
  Ces slivers sont ensuite supprimés avec la commande QGis Vecteur/Outils de géotraitement/Eliminer les polygones sélectionnés
  Dans certains cas, il est utile de supprimer les noeuds parasites ajoutés par les traitements.
  La couche est enregistrée en shp puis chargée dans PostGis dans la table eratcorrb
*/

select id, crat, nom, ST_AsText(geom) from eratcorrigee where id like '%c';

-- petit polygone ne fusionnant pas
delete from eratcorrb where id='27467c';
delete from eratcorrb where id='52064c';
delete from eratcorrb where id='49228c';

--
-- ITERATION
-- ITERER JUSQU'A ELIMINATION DES ECOMP plus petit que 0.165
-- soit en fusionnant les polygones soit en éditant les points
--

-- je crée les entités complémentaires (ecomp)
-- somme des entités rattachées groupées par rattachante
drop table if exists srattache;
create table srattache as
  select crat as id, ST_Union(geom) as geom
  from eratcorrb
  group by crat;

-- calcul des entités complémentaires éventuelles (416)
-- l'id est le code INSEE concaténé avec 'c'
drop table if exists ecomp;
create table ecomp as
  select concat(c.id, 'c') id, c.id crat, 0 npol, ST_Difference(c.wkb_geometry, sr.geom) geom
  from commune_carto c, srattache sr
  where c.id=sr.id and not ST_IsEmpty(ST_Difference(c.wkb_geometry, sr.geom));
comment on table ecomp is 'Entités complémentaires, cad complément éventuel des entités rattachées dans leur c. de rattachement';
drop table srattache;

insert into ecomp
select id, crat, npol2, ST_GeometryN(geom, npol2) geom
from ecomp, generate_series(1,1000) npol2
where GeometryType(geom)='MULTIPOLYGON' and npol2 <= ST_NumGeometries(geom);

delete from ecomp where GeometryType(geom)='MULTIPOLYGON' and npol=0;

-- calcul de leur surface en km2 et affichage des plus petites pour détecter les slivers
select id, crat, npol, ST_Area(geom)*40000*40000/360/360 as areaKm2
from ecomp
order by ST_Area(geom)*40000*40000/360/360; 
   id   | crat  | npol |       areakm2        
--------+-------+------+----------------------
 08362c | 08362 |    1 |    0.170180754444408
 ...
 
 
