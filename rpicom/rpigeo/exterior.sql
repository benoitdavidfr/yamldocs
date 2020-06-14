/*PhpDoc:
name: exterior.sql
title:  construction des limites extérieures des communes, cad les limites avec la mer ou l'étranger
doc: |
  construit les limites extérieures
  Une solution simple est de construire le polygone extérieur et de calculer son intersection avec les communes.
  C'est solution n'est pas performante car elle croise un grand nomble d'objet avec un grand objet sans aucun accélérateur.
  Une autre solution consiste à daller le polygone extérieur mais ce dallage crée des bugs car il n'est pas topologiquement cohérent.
  Un 3ème algorithme consiste à:
    1) fabriquer l'extérieur
    2) sur FXX qui pose problème, le décomposer en anneaux intérieurs, puis en segments
    3) réaffecter chaque segment à un code insee par croisement géométrique avec comm
    4) restructurer les segments en limites par code INSEE
    5) ajouter les DOM traités plus simplement car pas d'enjeu de perf.
*/

-- fusion des communes par département
create table comunionpardept as
select substring(id, 1, 2) as dept, ST_Union(wkb_geometry) as geom
from commune_carto
group by substring(id, 1, 2);
comment on table comunionpardept is 'Union géométrique des communes par département';

-- fusion des départements en un tuple pour FXX
create table comunionfxx as
select ST_Union(geom) as geom
from comunionpardept where dept<>'97';
comment on table comunionfxx is 'Union géométrique des communes de métropole';

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
-- je distingue FXX et DOM pour essayer d'optimiser le traitement
-- L'extérieur FXX est dallé en dalles de 1°x1° pour optimiser la création de comcomm
drop table if exists exterior;
create table exterior as
  select num, iso3, ST_Difference(box, geom) as geom
  from univers, comunionfxx
  where iso3='FXX' and ST_Dimension(ST_Difference(box, geom))=2
union
  select num, iso3, ST_Difference(box, geom) as geom
  from univers, comunionpardept
  where iso3<>'FXX' and dept='97';
comment on table exterior is 'Limite extérieure de chaque gde zone géo., cad la limite du territoire avec la mer ou l''étranger.';

-- decomposition du MultiPolygon en  1 Polygon
-- je ne comprends pas quel l'autre polygone dans exterior/FXX
drop table if exists extfxx;
create table extfxx as
  select 1 as n, ST_GeometryN(geom, 1) as geom
  from exterior
  where iso3='FXX';
  
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

-- decomposition de chaque anneau en segments, le plus grand compte 152.434 points / 204531
drop table if exists extfxxsegs;
create table extfxxsegs as
select nr, npt as nseg, ST_MakeLine(ST_PointN(geom, npt),ST_PointN(geom, npt+1)) as geom
from extfxxring, generate_series(1,160000) npt
where npt < ST_NumPoints(geom);
create index extfxxsegs_gist on extfxxsegs using gist(geom);

select nr, nseg, ST_AsText(geom) from extfxxsegs;

-- reaffectation de chaque segment à une comm (c. simple non rattachante, e. ratt. + compl.) / 1407
-- génère des LineString et des MultiLineString / 1519 / 6'
drop table if exists commextlim;
select 'Debut:', now();
create table commextlim as
  select 'FXX' iso3, id, type, ST_LineMerge(ST_Collect(s.geom)) geom -- FXX
  from extfxxsegs s, comm c
  where s.geom && c.geom and ST_Dimension(ST_Intersection(s.geom, c.geom))=1
  group by id, type
union
  select iso3, id, type, ST_LineMerge(ST_Intersection(c.geom, e.geom)) geom -- DOM
  from comm c, exterior e
  where e.iso3<>'FXX' and c.id like '97%' and c.geom && e.geom and ST_Dimension(ST_Intersection(c.geom, e.geom))=1;
select now();
comment on table commextlim is 'Limite ext. de chaque comm (coms, er, ecomp), cad limite avec la mer ou l''étranger.';

select id, ST_AsText(geom) from commextlim;

