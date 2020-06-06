-- Schéma de stockage des données historiques du rpicom fusionnant données INSEE et IGN et implémentant une topologie - 6/6/2020
-- La topologie est gérée par une carte topologique fusionnant les différentes versions disponibles
-- Certaines entités administratives, notammnt anciennes, ne sont pas géoréférencées
-- ==>> Voir comment représenter les simplifications topologiques

-- table des limites existantes ou ayant existé codant aussi les brins
create table edge(
  id serial primary key, -- le num. de limite, utilisé comme no de brin
  right int not null references face(id), -- la face à droite
  left  int not null references face(id), -- la face à gauche
  next  int not null, -- le brin suivant du brin positif dans son anneau, défini par un entier positif ou négatif
  prev  int not null, -- le brin suivant du brin inverse dans son anneau, défini par un entier positif ou négatif 
  geom  geometry(LINESTRING, 4326), -- la géométrie de la limite telle que définie dans la source IGN
  source char(10), -- source de la géométrie codée sous la forme 'AE{year}COG' ou 'AE{year}{month}' ou 'geofla{year}'
  simp3 geometry(LINESTRING, 4326)  -- la géométrie simplifiée de la limite avec une résolution de 1e-3 degrés (cad env. 100 m)
);

-- la table des faces - la face universelle est codée comme face no 1
create table face(
  id serial primary key
);

-- table des anneaux des faces, ==>> faut-il distinguer l'extérieur des anneaux intérieurs ?
create table ring(
  blade int primary key, -- le brin définissant l'anneau
  face int not null references face(id), -- la face à laquelle appartient l'anneau
  bbox geometry(POLYGON, 4326) -- la boite englobante de l'anneau codée comme un polygone
);

-- type d'entité administrative
create type admin_statut AS ENUM ('cSimple', 'cDéléguée', 'cAssociée', 'ardtMun');

-- table des versions d'entité administrative associées à leur évènement de fin
-- à la date de référence du jeu de données, une version est soit valide soit abrogée
-- à chaque version abrogée sont associés sa date de fin et son (ou ses) évt(s) de fin
-- Par ailleurs, si la date de création n'est pas le 1/1/1943 alors la création est détaillée
--  - soit par l'évt de fin de la version précédente pour le même cinsee dont fin vaut la date de création de la v. suivante
--  - soit sinon par un évènement de création pour ce cinsee et cette date de création qui documente la création
drop table if exists eadminv;
create table eadminv(
  id serial, -- ==>> utile ? potentiellement pour la table dérivée avec géométrie ?
  cinsee char(5) not null, -- code INSEE
  dcreation date not null, -- date de création de la version, 1/1/1943 par défaut
  fin date, -- lendemain du dernier jour, null ssi version encore valide
  statut admin_statut not null,
  crat char(5), -- pour une entité rattachée code INSEE de la c. de rattachement, null ssi cSimple
  nom varchar(256) not null, -- nom en minuscules
  evtFin jsonb, -- évènement(s) de fin, null ssi encore valide, il peut y en avoir plusieurs
  primary key (cinsee, dcreation)
);

-- évt de création d'une version d'entité administrative, (cinsee,dcreation) doit correspondre à (cinsee,debut) de eadminv
drop table if exists evtCreation;
create table evtCreation(
  cinsee char(5) not null, -- code INSEE
  dcreation date not null, -- date de l'évènement
  evt jsonb not null, -- l'évènement
  foreign key (cinsee, dcreation) REFERENCES eadminv (cinsee, dcreation)
);

-- définition de la géographie d'eadminv comme ensemble de faces
create table eadminvgeo(
  cinsee char(5) not null, -- code INSEE
  debut date not null, -- premier jour, 1/1/1943 par défaut
  face int not null references face(id),
  foreign key (cinsee, debut) REFERENCES eadminv (cinsee, debut)
);

-- chargement des tables temporaires des communes et des entités rattachées
ogr2ogr -f PostgreSQL PG:'host=172.17.0.4 port=5432 dbname=gis user=docker password=docker' \
  FRA/COMMUNE_CARTO_cor1.geojson -t_srs EPSG:4326 -nlt MULTIPOLYGON -nln commune_carto -overwrite
ogr2ogr -f PostgreSQL PG:'host=172.17.0.4 port=5432 dbname=gis user=docker password=docker' \
  FRA/entite_rattachee_carto_cor1.geojson -t_srs EPSG:4326 -nlt MULTIPOLYGON -nln entite_rattachee_carto -overwrite

CREATE UNIQUE INDEX commune_carto_id ON commune_carto(id);

L'enjeu est principalement de pouvoir
 1) effectuer un chargement
 2) effectuer une visualisation dans QGis
 
Peut-on reconstituer facilement la géométrie des faces par ST_Polygonize() ?
Peut-on reconstituer facilement la géométrie des eadminv ?
