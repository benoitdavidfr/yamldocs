/*PhpDoc:
name: schema.sql
title: schema.sql - schéma de la base Rpigéo
doc: |
journal: |
  13/6/2020
    - création par extraction de rpigeo2.sql
*/

/*PhpDoc: tables
name: admin_statut
title: admin_statut - type d'entité administrative - ENUM ('cSimple', 'cDéléguée', 'cAssociée', 'ardtMun')
database: [ rpigeo ]
*/
create type admin_statut AS ENUM ('cSimple', 'cDéléguée', 'cAssociée', 'ardtMun');
comment on type admin_statut is 'type d''entité administrative - ENUM (cSimple, cDéléguée, cAssociée, ardtMun)';

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
