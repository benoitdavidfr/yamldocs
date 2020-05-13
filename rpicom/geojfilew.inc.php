<?php

// fichier GeoJSON en écriture
class GeoJFileW {
  protected $file = null;
  protected $first;
  
  // création
  function __construct(string $filename, string $fcName, array $metadata) {
    $this->file = fopen($filename, 'w');
    fwrite($this->file, "{\n");
    fwrite($this->file, '"type": "FeatureCollection",'."\n");
    fwrite($this->file, "\"name\": \"$fcName\",\n");
    //fwrite($this->file, '"crs": { "type": "name", "properties": { "name": "urn:ogc:def:crs:OGC:1.3:CRS84" } },'."\n");
    foreach ($metadata as $key => $value)
      fwrite($this->file, "\"$key\": \"$value\",\n");
    fwrite($this->file, '"features": ['."\n");
    $this->first = true;
  }
  
  // fermeture
  function close() {
    fwrite($this->file, "\n]\n}\n");
    fclose($this->file);
    $this->file = null;
  }
  
  // écriture d'un feature
  function write(array $geojson): void {
    if (!$this->first) fwrite($this->file, ",\n");
    $this->first = false;
    fwrite($this->file, json_encode($geojson, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  }
};
