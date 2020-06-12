/*PhpDoc:
name: simplify.sql
title: simplify.sql - Test d'utilisation st_simplifyPreserveTopology() pour simplifier les communes - 1/6/2020
doc: |
  Ca ne marche pas car il y a trop de coordonnées
  utilisation de ma méthode définie dans https://trac.osgeo.org/postgis/wiki/UsersWikiSimplifyPreserveTopology
  st_simplifyPreserveTopology() n'utilise pas le module topology de PostGis
  Elle s'applique à un agrégat de polygones
*/
-- First extract the input multipolygons into polygons, keeping their departement code. This will allow us to associate attributes to each part of multipolygons at the end of the process. 
create table poly as (
  select gid, code_dept, (st_dump(geom)).* 
  from departement
);

create table poly as (
  select gid, cinsee, (st_dump(geom)).*
  from public.eadminv
  where statut='cSimple' and fin is null
);

-- extract rings out of polygons 
create table rings as (
  select st_exteriorRing((st_dumpRings(geom)).geom) as g 
  from poly
);

-- Simplify the rings. At this step, we choose the simplification ratio we want (some trials can be made by calling st_simplifyPreserveTopology on departement table). 
create table simplerings as (
  select st_simplifyPreserveTopology(st_linemerge(st_union(g)), 10000) as g 
  from rings
);

-- utilisation de la tolerance de 0.001 soit environ 100 m
drop table simplerings;
create table simplerings as (
  select st_simplifyPreserveTopology(st_linemerge(st_union(g)), 0.001) as g 
  from rings
);
-- la commande échoue après plusieurs heures

-- extract lines as individual objects, in order to rebuild polygons from these simplified lines 
create table simplelines as (
  select (st_dump(g)).geom as g 
  from simplerings
);

-- rebuild the polygons, first by polygonizing the lines, with a distinct clause to eliminate overlaping segments that may prevent polygon to be created, then dump the collection of polygons into individual parts, in order to rebuild our layer.
create table simplepolys as ( 
  select (st_dump(st_polygonize(distinct g))).geom as g
  from simplelines
);

-- Add an id column to help us identify objects and a spatial index 
alter table simplepolys add column gid serial primary key;
create index simplepolys_geom_gist on simplepolys using gist(g);


-- Second method is based on percentage of overlaping area comparison. Empirical ratio used here. 
create table simpledep as (
  select d.code_dept, s.g as geom
  from departement d, simplepolys s
  where st_intersects(d.geom, s.g)
  and st_area(st_intersection(s.g, d.geom))/st_area(s.g) > 0.5
);

-- rebuild departements by grouping them by code_dept (other attributes could be re-associated here): 
create table simple_departement as (
  select code_dept, st_collect(geom) as geom
  from simpledep
  group by code_dept
);

