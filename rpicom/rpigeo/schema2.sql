/*PhpDoc:
name: schema2.sql
title: schema2.sql - schéma de la base Rpigéo
doc: |
  Tient compte de la création de zones (bzone.php)
journal: |
  29/6/2020
    - modification de schema.sql pour prendre en compte la définition de zones
tables:
*/

/*PhpDoc: tables
name: admin_statut
title: admin_statut - statut détaillé d'entité administrative - ENUM ('cSimple', 'cDéléguée', 'cAssociée', 'ardtMun')
database: [ rpigeo ]
*/
create type admin_statut AS ENUM ('cSimple', 'cDéléguée', 'cAssociée', 'ardtMun');
comment on type admin_statut is 'type d''entité administrative - ENUM (cSimple, cDéléguée, cAssociée, ardtMun)';

/*PhpDoc: tables
name: admin_type
title: admin_type - type court d'entité administrative - ENUM ('s', 'r')
database: [ rpigeo ]
*/
create type admin_type AS ENUM ('s','r');
comment on type admin_type is 'type d''entité administrative - ENUM (s pour simple, r pour rattachée)';

/*PhpDoc: tables
name: eadminv
title: eadminv - table des versions d'entité administrative, source INSEE
doc: |
  Les données INSEE sont stockées sous la forme des 2 tables eadminv + evenement
  A la date de référence du jeu de données, une version est soit valide soit abrogée
  Une version abrogée correspond à une date de fin alors qu'une version valide n'en a pas (dfin = null)
  Une version correspond à un évènement de début et à un de fin, sauf:
    - si la version est valide alors il n'y a pas d'évènement de fin,
    - si la date de création est le 1/1/1943 alors il n'y a pas d'évènement de début.
  Enfin, un même code INSEE à une date donnée peut désigner une c. déléguée et sa rattachante ; dans ce cas 2 n-uplets sont créés.
  Et dans ce cas ils correspondent aux mêmes évènements.
database: [ rpigeo ]
*/
drop table if exists eadminv cascade;
create table eadminv(
  num serial, -- ==>> utile ? potentiellement pour la table dérivée avec géométrie ?
  cinsee char(5) not null, -- code INSEE, utilisé dans la clé
  type admin_type not null, -- type court s ou r, utilisé dans la clé car une c. déléguée et sa ratt. peuvent avoir même code Insee
  ddebut date not null, -- date de création de la version, 1/1/1943 par défaut, utilisé dans la clé
  dfin date, -- lendemain du dernier jour, null ssi version encore valide
  statut admin_statut not null, -- le statut détaillé
  crat char(5), -- pour une entité rattachée code INSEE de la c. de rattachement, null ssi cSimple
  nom varchar(256) not null, -- nom en minuscules accentuées
  primary key (cinsee, type, ddebut) -- la clé est composée du code Insee, du type et de la date de création
);
comment on table eadminv is 'Version d''entité administrative, source INSEE';

/*PhpDoc: tables
name: evenement
title: evenement - évt de début ou de fin d'une version d'entité administrative
doc: |
  Correspond aux évènements de début ou de fin d'une version d'entité administrative.
  Chaque version d'entités administrative correspond à un évènement de début et à un de fin à l'exception:
    - des versions qui débutent au 1/1/1943
    - des versions qui sont valides, cad qui n'ont pas de date de fin
  (cinsee,devt) doit correspondre à un couple (cinsee, ddebut ou dfin) de eadminv
database: [ rpigeo ]
*/
drop table if exists evenement;
create table evenement(
  cinsee char(5) not null, -- code INSEE
  devt date not null, -- date de l'évènement
  evt jsonb not null, -- définition de l'évènement codé en JSON
  primary key (cinsee, devt)
);
comment on table evenement is 'Evènement de début ou de fin d''une version d''entité administrative, source INSEE';

/*PhpDoc: tables
name: zone
title: zone - Classe d'équivalence des eadminv pour la relation d'égalité spatiale.
doc: |
  L'id de la zone est l'id de l'eadminv la plus ancienne.
database: [ rpigeo ]
*/
drop table if exists zone cascade;
create table zone(
  zoneid char(17) not null primary key, -- id de zone sous la forme {type}{cinsee(5)}'@'{dateDebut}
  ref varchar(256), -- référentiel le plus récent dans lequel la zone est géographiquement définie, null si aucun
  geom geometry(MULTIPOLYGON, 4326), -- la géométrie sous la forme d'un MultiPolygon
  cheflieu geometry(POINT, 4326) -- coordonnées du chef-lieu
);
comment on table zone is 'définition des zones';

create type zoneapour AS ENUM ('parent','enfant');
comment on type admin_type is 'type de relation entre 2 zones';

drop table if exists zoneapourzone;
create table zoneapourzone(
  zone1 char(17) not null references zone(zoneid),
  apour zoneapour not null, -- parent: z1 a pour parent z2, enfant: z1 a pour enfant z2
  zone2 char(17) not null references zone(zoneid)
);
comment on table zoneapourzone is 'Relations entre zones';

/*PhpDoc: tables
name: eadmvzone
title: eadmvzone - Correspondance entre entités admin. et zones
doc: |
  eadmvzone indique les eadminv ayant même zone.
database: [ rpigeo ]
*/
drop table if exists eadmvzone;
create table eadmvzone(
  eadminv integer not null references eadminv(num), -- reference à une eadminv
  zoneid char(17) not null references zone(zoneid) -- id de zone sous la forme {type}{cinsee(5)}'@'{dateDebut}
);
comment on table eadmvzone is 'Correspondance entre versions d''entités admin. et zones';

/*PhpDoc: tables
name: lim
title: lim - limite entre zones ou avec l'extérieur
doc: |
  Chaque zone est décrite géométriquement par l'ensemble de ses limites défini par zonelim
  Une limite commune entre 2 zones existe une seule fois.
database: [ rpigeo ]
*/
drop table if exists lim cascade;
create table lim(
  num serial primary key, -- le num. de limite
  geom  geometry(LINESTRING, 4326), -- la géométrie de la limite telle que définie dans la source
  source char(10), -- source de la géométrie codée sous la forme 'AE{year}COG' ou 'AE{year}{month}' ou 'geofla{year}'
  simp3 geometry(LINESTRING, 4326)  -- la géométrie simplifiée de la limite avec une résolution de 1e-3 degrés (cad env. 100 m)
);
comment on table lim is 'Limite entre entités ou avec l''extérieur';
create index lim_geom_gist on lim using gist(geom);

/*PhpDoc: tables
name: zonelim
title: zonelim - participation d'une limite à la description du contour d'une zone
database: [ rpigeo ]
*/
drop table if exists zonelim;
create table zonelim(
  zoneid char(17) not null, -- id de zone sous la forme {statut_court}{cinsee(5)}'@'{dateDebut}
  limnum int not null references lim(num) -- num de la limite
);
comment on table zonelim is 'Participation d''une limite à la description du contour d''une zone';


