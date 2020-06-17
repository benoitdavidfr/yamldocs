<?php
// génération de rectangles correspondant à un pavage de l'univers pour FXX

//('FXX', ST_MakeEnvelope(-6, 41, 10, 52, 4326)),

for($lon=-6; $lon < 10; $lon++)
  for($lat=41; $lat < 52; $lat++)
    printf("('FXX%d,%d', ST_MakeEnvelope(%d, %d, %d, %d, 4326)),\n", $lon, $lat, $lon, $lat, $lon+1, $lat+1);