/*PhpDoc:
name: exterior3.sql
title:  construction des limites extérieures des éléments, cad les limites avec la mer ou l'étranger
doc: |
  Construit les limites extérieures des éléments définis dans rpigeo3.sql
  Nlle solution après exterior.sql qui génère des erreurs car l'extérieur n'est pas calculé sur les éléments
  Algorithme:
    1) fabriquer l'extérieur à partir des éléments et non des communes
    2) sur FXX qui pose problème, le décomposer en anneaux intérieurs, puis en segments
    3) réaffecter chaque segment à un code insee par croisement géométrique avec comm
    4) restructurer les segments en limites par code INSEE
    5) ajouter les DOM traités plus simplement car pas d'enjeu de perf.
*/

-- fusion des éléments par département
create table unionpardept as
select substring(id, 1, 2) as dept, ST_Union(geom) as geom
from elt
group by substring(id, 1, 2);
comment on table unionpardept is 'Union géométrique des éléments par département';

-- fusion des départements en un tuple pour FXX
create table unionfxx as
select ST_Union(geom) as geom
from unionpardept where dept<>'97';
comment on table unionfxx is 'Union géométrique des éléments de métropole';

-- Un rectangle englobant par grande zone géographique
drop table if exists univers cascade;
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
  from univers, unionfxx
  where iso3='FXX' and ST_Dimension(ST_Difference(box, geom))=2
union
  select num, iso3, ST_Difference(box, geom) as geom
  from univers, unionpardept
  where iso3<>'FXX' and dept='97';
comment on table exterior is 'Extérieur pour chaque gde zone géo., cad MultiPolygone correspondant à la mer et l''étranger.';

select ST_NumGeometries(geom) from exterior where iso3='FXX'; -- 13
select ST_AsText(geom) from exterior where iso3='FXX';

-- decomposition du MultiPolygon en 13 Polygones
-- 1 polygone correspond au réel extérieur
-- 1 polygone correspond à l'enclave espagnole de Llivia
-- les 11 autres sont des erreurs de topologie
drop table if exists extfxx;
create table extfxx as
  select npol, ST_GeometryN(geom, npol) as geom
  from exterior, generate_series(1,100) npol
  where iso3='FXX' and npol <= ST_NumGeometries(geom);
  
select n, ST_AsText(geom) from extfxx;


select ST_NumInteriorRings(geom)
from exterior
where iso3='FXX';

select ST_AsText(geom)
from exterior
where iso3='FXX';

-- decomposition du polygone en anneaux intérieurs
drop table if exists extfxxring;
create table extfxxring as
select nr, ST_InteriorRingN(geom, nr) as geom
from extfxx, generate_series(1,100) nr
where nr <= ST_NumInteriorRings(geom);

select n, ST_NPoints(geom), ST_AsText(geom) from extfxxring;

-- decomposition de chaque anneau en segments, le plus grand compte 152.434 points / 
drop table if exists extfxxsegs;
create table extfxxsegs as
select nr, npt as nseg, ST_MakeLine(ST_PointN(geom, npt),ST_PointN(geom, npt+1)) as geom
from extfxxring, generate_series(1,160000) npt
where npt < ST_NumPoints(geom);
create index extfxxsegs_gist on extfxxsegs using gist(geom);

select nr, nseg, ST_AsText(geom) from extfxxsegs;

-- reaffectation de chaque segment à un élémnt / 
-- génère des LineString et des MultiLineString / 1519 / 6'
drop table if exists eltextlim;
select 'Debut:', now();
create table eltextlim as
  select 'FXX' iso3, id, type, ST_LineMerge(ST_Collect(s.geom)) geom -- FXX
  from extfxxsegs s, elt e
  where s.geom && e.geom and ST_Dimension(ST_Intersection(s.geom, e.geom))=1
  group by id, type
union
  select iso3, id, type, ST_LineMerge(ST_Intersection(elt.geom, ext.geom)) geom -- DOM
  from elt, exterior ext
  where ext.iso3<>'FXX' and elt.id like '97%'
    and elt.geom && ext.geom and ST_Dimension(ST_Intersection(elt.geom, ext.geom))=1;
select now();
comment on table eltextlim is 'Limite ext. de chaque élément, cad limite avec la mer ou l''étranger.';

select id, ST_AsText(geom) from eltextlim;

