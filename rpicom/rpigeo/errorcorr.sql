/*PhpDoc:
name: errorcorr.sql
title: errorcorr.sql - vérifie la cohérence topologique et corrige les erreurs topologiques dans les données IGN en entrée
doc: |
  Ce script
    - vérifie que les commune_carto sont cohérentes topologiquement, cad
      - elles ne s'intersectent entre elles que par des lignes ou des points
      - leur union couvre le territoire,
        - pour cela on vérifie que seul FXX a une enclave dans son territoire, à savoir l'enclave espagnole de Llivia
    - produit une couche corrigée des entités rattachées qui NE sont PAS cohérentes topologiquement avec commune_carto
      - on vérifie que les entités rattachées ne s'intersectent pas entre elles
      - on limite chaque entité rattachée à sa commune rattachante, 
      - on calcule les entités complémentaires
      - on identifie parmi elles celles qui sont des sliver que l'on agrège avec une entité voisine, pour cela
        - on vérifie individuellement dans QGis les plus petites
  Tables en entrée:
    - commune_carto
    - entite_rattachee_carto
  Tables en sortie:
    - eratcorrigee - Entités rattachées corrigées
    - ecomp - Entités complémentaires, cad complément éventuel des entités rattachées dans les c. rattachantes
    - univers - Un rectangle englobant par grande zone géographique (FXX et chaque DOM)
    - comunionfxxdom - Union géométrique des communes, 1 pour FXX et 1 pour les DOM
    - exterior - Extérieur pour chaque gde zone géo., cad MultiPolygone correspondant à la mer et l'étranger
    - extfxx - Extérieur de FXX décomposé en 2 polygones, 1 pour l'extérieur et l'autre pour l'enclave espagnole de Llivia
    - comint - Intersections entre commune_carto 2 à 2
    - erint - Intersections entre entite_rattachee_carto 2 à 2
    - srattache - union géométrique des entités rattachées groupées par rattachante
journal: |
  14/6/2020
    - première version
*/
/*
Topologie:
- sliver
- overlap
*/
-- .sql - 
-- la stratégie est de se fonder sur les communes simples pour calculer l'extérieur
-- et de corriger les entitee_rattachee pour les mettre en cohérence avec leur rattachante

--------------------------------
-- 1) Vérification des communes
--------------------------------
-- 1a) vérifier que les communes ne s'intersectent que comme ligne ou point
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
-- -> vide

-- si c'est une GEOMETRYCOLLECTION alors elle n'est composée que de POINT, LINESTRING ou MULTILINESTRING
select id1, id2, numgeom, ST_GeometryN(geom, numgeom) geom
from comint, generate_series(1,100) numgeom
where GeometryType(geom)='GEOMETRYCOLLECTION'
  and numgeom < ST_NumGeometries(geom)
  and GeometryType(ST_GeometryN(geom, numgeom))<>'MULTILINESTRING'
  and GeometryType(ST_GeometryN(geom, numgeom))<>'LINESTRING'
  and GeometryType(ST_GeometryN(geom, numgeom))<>'POINT';
-- -> vide

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
comment on table exterior is 'Extérieur pour chaque gde zone géo., cad MultiPolygone correspondant à la mer et l''étranger.';

select iso3, ST_AsText(geom) from exterior;

drop table if exists extfxx;
create table extfxx as
  select npol, ST_GeometryN(geom, npol) as geom
  from exterior, generate_series(1,100) npol
  where iso3='FXX' and npol <= ST_NumGeometries(geom);
-- contient 2 polygones, le 1er l'extérieur et le 2nd l'enclave espagnole de Llivia
comment on table extfxx is 'Extérieur de FXX en 2 polygones, 1 pour l''extérieur et l''autre pour l''enclave espagnole de Llivia';

----------------------------------------
-- 2) Correction des entités rattachées
----------------------------------------
-- 2a) vérifier que les entités rattachées ne s'intersectent que comme ligne ou point
create table erint as
select c1.id id1, c2.id id2, ST_Intersection(c1.wkb_geometry, c2.wkb_geometry) geom
from entite_rattachee_carto c1, entite_rattachee_carto c2
where c1.id < c2.id and c1.wkb_geometry && c2.wkb_geometry and ST_Intersects(c1.wkb_geometry, c2.wkb_geometry);
comment on table erint is 'Intersections entre entite_rattachee_carto 2 à 2';

-- l'intersection entre 2 er est soit un POINT, une LINESTRING, une MULTILINESTRING ou une GEOMETRYCOLLECTION
select id1, id2, GeometryType(geom)
from erint
where GeometryType(geom)<>'MULTILINESTRING'
  and GeometryType(geom)<>'LINESTRING'
  and GeometryType(geom)<>'POINT'
  and GeometryType(geom)<>'GEOMETRYCOLLECTION';
-- -> vide

-- si c'est une GEOMETRYCOLLECTION alors elle n'est composée que de POINT, LINESTRING ou MULTILINESTRING
select id1, id2, numgeom, ST_GeometryN(geom, numgeom) geom
from erint, generate_series(1,100) numgeom
where GeometryType(geom)='GEOMETRYCOLLECTION'
  and numgeom < ST_NumGeometries(geom)
  and GeometryType(ST_GeometryN(geom, numgeom))<>'MULTILINESTRING'
  and GeometryType(ST_GeometryN(geom, numgeom))<>'LINESTRING'
  and GeometryType(ST_GeometryN(geom, numgeom))<>'POINT';
-- -> vide

-- 2b) chaque er doit être strictement incluse dans sa c. rattachante
-- er - crat est vide
select er.id, ST_AsText(ST_Difference(er.wkb_geometry, c.wkb_geometry))
from entite_rattachee_carto er, commune_carto c
where er.insee_ratt=c.id
  and not ST_IsEmpty(ST_Difference(er.wkb_geometry, c.wkb_geometry));
-- -> liste les erreurs

-- création d'une table des entités rattachées corrigées
drop table if exists eratcorrigee;
create table eratcorrigee as
select er.ogc_fid, er.id, er.nom_com as nom, er.insee_ratt as crat, er.type, ST_Intersection(er.wkb_geometry, c.wkb_geometry) as geom
from entite_rattachee_carto er, commune_carto c
where er.insee_ratt=c.id;
comment on table eratcorrigee is 'entités rattachées corrigées';
create index eratcorrigee_geom_gist on eratcorrigee using gist(geom);

-- 1) créer les entités complémentaires (ecomp)
-- somme des entités rattachées groupées par rattachante
drop table if exists srattache;
create table srattache as
  select crat as id, ST_Union(geom) as geom
  from eratcorrigee
  group by crat;

-- calcul des entités complémentaires éventuelles (416)
-- l'id est le code INSEE concaténé avec 'c'
drop table if exists ecomp;
create table ecomp as
  select concat(c.id, 'c') id, c.id crat, ST_Difference(c.wkb_geometry, sr.geom) geom
  from commune_carto c, srattache sr
  where c.id=sr.id and not ST_IsEmpty(ST_Difference(c.wkb_geometry, sr.geom));
  
select id, ST_Area(geom)*40000*40000/360/360 as areaKm2
from ecomp
order by ST_Area(geom)*40000*40000/360/360; 

-- 52064 = (52064 + 52064c)
update eratcorrigee
  set geom=(select ST_Union(r.geom, c.geom) from eratcorrigee r, ecomp c where r.id='52064' and c.id='52064c')
  where id='52064';
delete from ecomp where id='52064c';

-- solution: 08079 = (08079 + 08173c)
update eratcorrigee
  set geom=(select ST_Union(r.geom, c.geom) from eratcorrigee r, ecomp c where r.id='08079' and c.id='08173c')
  where id='08079';
delete from ecomp where id='08173c';

-- solution: 27467 = (27467 + 27467c)
update eratcorrigee
  set geom=(select ST_Union(r.geom, c.geom) from eratcorrigee r, ecomp c where r.id='27467' and c.id='27467c')
  where id='27467';
delete from ecomp where id='27467c';

-- solution: 28262 = (28262 + 28103c)
update eratcorrigee
  set geom=(select ST_Union(r.geom, c.geom) from eratcorrigee r, ecomp c where r.id='28262' and c.id='28103c')
  where id='28262';
delete from ecomp where id='28103c';

-- solution: 14201 = (14201 + 14431c)
update eratcorrigee
  set geom=(select ST_Union(r.geom, c.geom) from eratcorrigee r, ecomp c where r.id='14201' and c.id='14431c')
  where id='14201';
delete from ecomp where id='14431c';

-- solution: 72137 = (72137 + 72137c)
update eratcorrigee
  set geom=(select ST_Union(r.geom, c.geom) from eratcorrigee r, ecomp c where r.id='72137' and c.id='72137c')
  where id='72137';
delete from ecomp where id='72137c';

-- solution: 49103 = (49103 + 49228c)
update eratcorrigee
  set geom=(select ST_Union(r.geom, c.geom) from eratcorrigee r, ecomp c where r.id='49103' and c.id='49228c')
  where id='49103';
delete from ecomp where id='49228c';

-- solution: 48105 = (48105 + 48105c)
update eratcorrigee
  set geom=(select ST_Union(r.geom, c.geom) from eratcorrigee r, ecomp c where r.id='48105' and c.id='48105c')
  where id='48105';
delete from ecomp where id='48105c';
