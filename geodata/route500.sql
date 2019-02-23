drop table if exists route500.troncon_route;
create table route500.troncon_route (
  id_rte500 integer not null,
  vocation ENUM('Liaison locale','Liaison principale','Liaison régionale','Type autoroutier') not null,
  nb_chausse ENUM('1 chaussée','2 chaussées') not null,
  nb_voies ENUM('1 voie ou 2 voies étroites','2 voies larges','3 voies','4 voies','Plus de 4 voies','Sans objet') not null,
  etat ENUM('Non revêtu','Revêtu') not null,
  acces ENUM('A péage','Inconnu','Libre','Saisonnier') not null,
  res_vert ENUM('Appartient','N\'appartient pas') not null,
  sens ENUM('Double sens','Sens inverse','Sens unique') not null,
  res_europe varchar(4) not null,
  num_route varchar(10) not null,
  class_adm ENUM('Autoroute','Départementale','Nationale','Sans objet') not null,
  longueur decimal(13,2) not null,
  geom Geometry not null
)
ENGINE = MYISAM
DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
create spatial index troncon_route_geom on route500.troncon_route(geom);
