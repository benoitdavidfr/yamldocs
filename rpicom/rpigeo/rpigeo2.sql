/*PhpDoc:
name: rpigeo2.sql
title: rpigeo2.sql - constitution de la base Rpigéo par des requêtes PostGis
doc: |
  Ecriture de requêtes PostGis pour
    1) déduire les limites entre communes des communes fournies en polygone par l'IGN
    2) stocker ces limites en les partageant
      d'une part entre communes et entités rattachées et
      d'autre part entre versions successives des communes
    3) définir comme vue matérialisée les communes à partir de ces limites
    4) définir une géométrie simplifiée des limites et des communes
journal: |
  13/6/2020
    - 1:00 code ok pour le D17
    - lancement du traitement sur tt FRA
    - pb de perf sur le calcul -> prend plusieurs heures
    - test d'optimisation en dallant l'extérieur
      - -> introduit des bugs du fait que ces dalles ne sont pas dans la même topologie
    - test d'une autre optimisation
      - calculer séparément les limites entre communes et celles avec l'extérieur
      - utiliser le code existant pour les limites entre communes
    - une dizaine d'erreurs probablement dues aux incohérences des données en entrée
    - passage à la V3 avec objectif de détecter et traiter les erreurs IGN en entrée
  12/6/2020:
    - modif du schema pour permettre à un même code INSEE de correspondre à la fois à une commune simple et à une commune déléguée
  11/6/2020:
    - test sur D19
  10/6/2020:
    - définition du schéma de la base eadminv, lim, eadmvlim plus la vue matérialisée eadmvpol
    - production pour les communes2020 de Corse et
    - génération des communes à partir des limites
      - avec qqs anomalies non traitées et
      - génération de la version généralisée
  9/6/2020:
    - première version
tables:
*/

/*PhpDoc: tables
name: admin_statut
title: admin_statut - type d'entité administrative
database: [ rpigeo ]
*/
create type admin_statut AS ENUM ('cSimple', 'cDéléguée', 'cAssociée', 'ardtMun');

/*PhpDoc: tables
name: eadminv
title: eadminv - table des versions d'entité administrative associées à leur évènement de fin, source INSEE
doc: |
  Les données INSEE sont stockées sous la forme des 2 tables eadminv + evtCreation
  A la date de référence du jeu de données, une version est soit valide soit abrogée
  A chaque version abrogée sont associés sa date de fin et son (ou ses) évt(s) de fin
  Par ailleurs, si la date de création n'est pas le 1/1/1943 alors la création est détaillée
   - soit par l'évt de fin de la version précédente pour le même cinsee dont fin vaut la date de création de la v. suivante
   - soit sinon par un évènement de création pour ce cinsee et cette date de création qui documente la création
  Enfin, un même code INSEE à une date donnée peut désigner une c. déléguée et sa rattachante ; dans ce cas 2 n-uplets sont créés.
database: [ rpigeo ]
*/
drop table if exists eadminv cascade;
create table eadminv(
  num serial, -- ==>> utile ? potentiellement pour la table dérivée avec géométrie ?
  cinsee char(5) not null, -- code INSEE
  dcreation date not null, -- date de création de la version, 1/1/1943 par défaut
  fin date, -- lendemain du dernier jour, null ssi version encore valide
  statut admin_statut not null,
  crat char(5), -- pour une entité rattachée code INSEE de la c. de rattachement, null ssi cSimple
  nom varchar(256) not null, -- nom en minuscules
  evtFin jsonb, -- évènement(s) de fin, null ssi encore valide, il peut y en avoir plusieurs
  primary key (cinsee, dcreation, statut) -- le statut dans la clé car une c. déléguée et sa rattachante peuvent avoir même code Insee
);
comment on table eadminv is 'Version d''entité administrative associées à leur évènement de fin, source INSEE';

/*PhpDoc: tables
name: evtCreation
title: evtCreation - évt de création d'une version d'entité administrative
doc: |
  (cinsee,dcreation) doit correspondre à un couple (cinsee,debut) de eadminv
database: [ rpigeo ]
*/
drop table if exists evtCreation;
create table evtCreation(
  cinsee char(5) not null, -- code INSEE
  dcreation date not null, -- date de l'évènement
  evt jsonb not null, -- l'évènement
  primary key (cinsee, dcreation)
);
comment on table evtCreation is 'Evènement de création d''une version d''entité administrative, source INSEE';

/*PhpDoc: tables
name: lim
title: lim - limite entre communes ou avec l'extérieur
doc: |
  Chaque commune est décrite par l'ensemble de ses limites défini par eadmvlim
  Une limite commune entre 2 communes existe une seule fois.
  De même si une limite commune entre des versions différentes ou entre communes et entités rattachées existe une seule fois
database: [ rpigeo ]
*/
drop table if exists lim cascade;
create table lim(
  num serial primary key, -- le num. de limite
  geom  geometry(LINESTRING, 4326), -- la géométrie de la limite telle que définie dans la source IGN
  source char(10), -- source de la géométrie codée sous la forme 'AE{year}COG' ou 'AE{year}{month}' ou 'geofla{year}'
  simp3 geometry(LINESTRING, 4326)  -- la géométrie simplifiée de la limite avec une résolution de 1e-3 degrés (cad env. 100 m)
);
comment on table lim is 'Limite entre communes ou avec l''extérieur';
create index lim_geom_gist on lim using gist(geom);

/*PhpDoc: tables
name: eadmvlim
title: eadmvlim - participation d'une limite à la description du contour d'une commune
database: [ rpigeo ]
*/
drop table if exists eadmvlim;
create table eadmvlim(
  cinsee char(5) not null, -- code INSEE
  dcreation date not null, -- date de création de la version
  statut admin_statut not null, -- statut de la version
  foreign key (cinsee, dcreation, statut) references eadminv (cinsee, dcreation, statut),
  limnum int not null references lim(num) -- num de la limite
);
comment on table eadmvlim is 'Participation d''une limite à la description du contour d''une commune';

-------------------------------------
--    Traitements de constitution
-------------------------------------

/* stats
34.968 commune_carto
 2.931 entite_rattachee_carto
*/


/* Algorithme (10/6/2020)
1) Si une commune est hétérogène avec des entités rattachées et des parties non couvertes,
   alors créer une pseudo-entité rattachée que l'on appele complémentaire
   fin_si
2) Avant de créer les limites entre communes, substituer aux communes ayant des entités rattachées (ie c. rattachantes)
   leurs entités rattachées + complémentaires.
3) Fabriquer les limites comcom17m
4) générer les eadmvlim
5) calculer les limites des c. rattachantes et les ajouter à eadmvlim
*/

-- 1) créer les entités complémentaires (ecomp)
-- somme des entités rattachées groupées par rattachante
drop table if exists srattache;
create table srattache as
  select insee_ratt as cinsee, ST_Union(wkb_geometry) as geom
  from entite_rattachee_carto
  group by insee_ratt;

-- calcul des entités complémentaires éventuelles (416)
-- l'id est le code INSEE concaténé avec 'c'
drop table if exists ecomp;
create table ecomp as
  select concat(c.id, 'c') id, c.id insee_ratt, ST_Difference(c.wkb_geometry, sr.geom) geom
  from commune_carto c, srattache sr
  where c.id=sr.cinsee and ST_Dimension(ST_Difference(c.wkb_geometry, sr.geom))=2;

-- affichage
select id, ST_Dimension(geom), ST_AsText(geom) from ecomp;

-- 2) fabrication d'un com modifié en substituant aux c. rattachantes leurs entités rattachées + complémentaires.
-- + l'extérieur (37239)
drop table if exists comm;
create table comm as
  -- les c. s. non rattachantes
  select id, 'cSimple' as type, wkb_geometry as geom
  from commune_carto
  where id not in (select insee_ratt from entite_rattachee_carto)
union
  -- les e. rattachées / type vaut COMA, COMD ou ARM
  select id, type, wkb_geometry from entite_rattachee_carto
union
  -- les complémentaires
  select id, 'ec' as type, geom from ecomp;
--union
  --select iso3, 'ext' as type, geom from exterior;
create index comm_geom_gist on comm using gist(geom);

-- 3) tables des limites entre communes + e. ratt. + e. comp. de D17 + extérieur
-- ST_Dimension()=1 supprime les points et les GeometryCollection vides
-- ST_Intersection() génère des lignes structurées comme ensemble de segments (MultiLineString)
-- ST_LineMerge() reconstruit des LineString
-- sans l'extérieur, prend 4' sur Mac
drop table if exists comcomm;
select 'Début:', now();
create table comcomm as 
select c1.id id1, c1.type typ1, c2.id id2, c2.type typ2, ST_LineMerge(ST_Intersection(c1.geom, c2.geom)) geom
from comm c1, comm c2
where c1.geom && c2.geom and c1.id < c2.id and ST_Dimension(ST_Intersection(c1.geom, c2.geom))=1;
select 'Fin:', now();
create index comcomm_geom_gist on comcomm using gist(geom);

-- ajout des limites exterieures extfxxseginsee, voir exterior.sql / 1407
insert into comcomm(id1, typ1, id2, typ2, geom)
  select id, type, iso3, 'ext', geom
  from commextlim;

-- je remplis la table lim à partir de comcom17m en ajoutant un serial et en décomposant les MultiLineString en LineString / 111130
truncate table eadmvlim cascade;
truncate table lim cascade;
insert into lim(geom, source)
  select geom, 'AE2020COG'
  from comcomm
  where GeometryType(geom)='LINESTRING'
union
  select ST_GeometryN(geom, n), 'AE2020COG'
  from comcomm, generate_series(1,100) n
  where GeometryType(geom)<>'LINESTRING'
    and n <= ST_NumGeometries(geom);

-- 4) je remplis la table eadmvlim en cherchant pour chaque code insee le numéro de limite
-- attention, je perd les limites des complémentaires qui ne sont pas des eadmimv
-- il faut retrouver le bon n-uplet dans eadminv cad en tenant compte du statut / 217544 
select 'Début:', now();
insert into eadmvlim(cinsee, dcreation, statut, limnum)
  select cinsee, dcreation, statut, lim.num
  from eadminv, comcomm cc, lim
  where (  (id1=cinsee and ((cc.typ1='cSimple' and statut='cSimple') or (cc.typ1<>'cSimple' and statut<>'cSimple')))
        or (id2=cinsee and ((cc.typ2='cSimple' and statut='cSimple') or (cc.typ2<>'cSimple' and statut<>'cSimple'))))
    and fin is null and lim.geom && cc.geom and ST_Dimension(ST_Intersection(lim.geom, cc.geom))=1;
select 'Fin:', now();

-- 4bis) vérifier les polygones générés à parir des limites / 36965
-- constater que les polygones couvrent l'ens. du territoire à l'exception des ecomp
drop table if exists eadmvpol;
create table eadmvpol as
select cinsee, dcreation, statut, (ST_Dump(ST_Polygonize(geom))).geom as geom
from lim, eadmvlim
where eadmvlim.limnum=lim.num
group by cinsee, dcreation, statut;

Erreurs:
  - absence de la limite entre l'er 72318 et la cs 72056'
    - -> probablement lié au bug IGN d'incohérence topologique limite entre 72069 et 72137'
 - ecomp 72137 erroné
 - 72318 c. déléguée de 72137 est absente
 - 72056 c. simple voisine est aussi absente

 - 27467 et 27385 absentes
 - lié au bug IGN
 
 - 08079 + 08140 + 08400 absents
 - polygone ecomp 08173c erroné
 
 - 49221

 - 43245
 
 - 66005
 
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
from comcomm cc, entite_rattachee_carto er
where er.id=cc.id1 or er.id=cc.id2
group by insee_ratt, cc.geom
having count(*)=1;

-- select id, type, wkb_geometry from entite_rattachee_carto

-- insertion dans la table eadmvlim des limites des c. rattachantes
insert into eadmvlim(cinsee, dcreation, statut, limnum)
  select cinsee, dcreation, statut, lim.num
  from eadminv, limrattachante lr, lim
  where cinsee=insee_ratt and fin is null and statut='cSimple'
    and lim.geom && lr.geom and ST_Dimension(ST_Intersection(lim.geom, lr.geom))=1;

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

