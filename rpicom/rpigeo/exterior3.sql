/*PhpDoc:
name: exterior3.sql
title:  construction des limites extérieures des éléments, cad leurs limites avec la mer ou l'étranger
doc: |
  Construit les limites extérieures des éléments définis dans rpigeo3.sql
  Nlle solution après exterior.sql qui génère des erreurs car l'extérieur n'est pas calculé sur les éléments
  Algorithme:
    1) réutilisation de extfxx produit dans errorcorr.sql
    2) sur FXX qui pose problème, le décomposer en anneaux intérieurs, puis en segments
    3) réaffecter chaque segment à un code insee par croisement géométrique avec elt
    4) restructurer les segments en limites par code INSEE
    5) ajouter les DOM traités plus simplement car pas d'enjeu de perf.
    6) ajouter l'enclave espagnole de Llivia 
  Tables en entrée:
    - elt produit dans rpigeo3.sql
    - extfxx produit dans errorcorr.sql
  Tables en sortie:
    - eltextlim - limites extérieures des éléments
  Tables temporaires:
    - extfxxring - anneaux intérieurs de l'extérieur FXX
    - extfxxseg - segments de droite de l'extérieur FXX
*/

select npol, ST_AsText(geom) from extfxx;

-- décomposition du polygone extérieur en anneaux intérieurs
drop table if exists extfxxring;
create table extfxxring as
select nr, ST_InteriorRingN(geom, nr) as geom
from extfxx, generate_series(1,100) nr
where nr <= ST_NumInteriorRings(geom);

select n, ST_NPoints(geom), ST_AsText(geom) from extfxxring;

-- decomposition de chaque anneau intérieur du grand polygone en segments, le plus grand compte 152.434 points / 204531
drop table if exists extfxxseg;
select 'Début:', now();
create table extfxxseg as
select nr, npt as nseg, ST_MakeLine(ST_PointN(geom, npt),ST_PointN(geom, npt+1)) as geom
from extfxxring, generate_series(1,160000) npt
where npt < ST_NumPoints(geom);
select 'Fin:', now();
create index extfxxseg_gist on extfxxseg using gist(geom);

select nr, nseg, ST_AsText(geom) from extfxxseg;

-- reaffectation de chaque segment à un élément pour créer les limites extérieures des éléments / 
-- génère des LineString et des MultiLineString / 1519 / 6'
drop table if exists eltextlim;
select 'Debut:', now();
create table eltextlim as
  select 'FXX' iso3, id, type, ST_LineMerge(ST_Collect(s.geom)) geom -- FXX
  from extfxxseg s, elt e
  where s.geom && e.geom and ST_Dimension(ST_Intersection(s.geom, e.geom))=1
  group by id, type
union
  select 'FXX' iso3, id, type, ST_LineMerge(ST_Intersection(elt.geom, ef.geom)) geom -- enclave de Llivia 
  from elt, extfxx ef
  where ef.npol=2 -- c'est la géométrie de l'enclave
    and elt.geom && ef.geom and ST_Dimension(ST_Intersection(elt.geom, ef.geom))=1
union
  select iso3, id, type, ST_LineMerge(ST_Intersection(elt.geom, ext.geom)) geom -- DOM
  from elt, exterior ext
  where ext.iso3<>'FXX' and elt.id like '97%'
    and elt.geom && ext.geom and ST_Dimension(ST_Intersection(elt.geom, ext.geom))=1;
select now();
comment on table eltextlim is 'Limite ext. de chaque élément, cad limite avec la mer ou l''étranger.';

select id, ST_AsText(geom) from eltextlim;

drop table extfxxring;
drop table extfxxseg;
