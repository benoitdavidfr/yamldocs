<?php
/*PhpDoc:
name: datasets.inc.php
title: datasets.inc.php - liste des datasets utilisés dans l'index IndGeoJFile
doc: |
journal: |
  2/5/2020:
    - 1ère version ok
*/

class Datasets {
  const DATASETS = [
    'Ae2020Cog' => [
      'path'=> 'AE2020COG/FRA/COMMUNE_CARTO',
      'year'=> 2020,
    ],
    'Ae2020CogR' => [
      'path'=> 'AE2020COG/FRA/ENTITE_RATTACHEE_CARTO',
      'year'=> 2020,
      'rattachées'=> true,
    ],
    'Ae2019Cog' => [
      'path'=> 'AE2019COG/FRA/COMMUNE_CARTO',
      'year'=> 2019,
    ],
    'Ae2018Cog' => [
      'path'=> 'AE2018COG/FRA/COMMUNE_CARTO',
      'year'=> 2018,
    ],
    'Ae2017Cog' => [
      'path'=> 'AE2017COG/FRA/COMMUNE_CARTO',
      'year'=> 2017,
    ],
    'geofla2016' => [
      'path'=> 'geofla2016/FXX/COMMUNE',
      'year'=> 2016,
    ],
    'geofla2015' => [
      'path'=> 'geofla2015/FXX/COMMUNE',
      'year'=> 2015,
    ],
    'geofla2014' => [
      'path'=> 'geofla2014/FRA/COMMUNE',
      'year'=> 2014,
    ],
    'geofla2013' => [
      'path'=> 'geofla2013/FRA/COMMUNE',
      'year'=> 2013,
    ],
    'geofla2012' => [
      'path'=> 'geofla2012/FXX/COMMUNE',
      'year'=> 2012,
    ],
    'geofla2011' => [
      'path'=> 'geofla2011/FXX/COMMUNE',
      'year'=> 2011,
    ],
    'geofla2010' => [
      'path'=> 'geofla2010/FXX/COMMUNE',
      'year'=> 2010,
    ],
    'geofla2003' => [
      'path'=> 'geofla2003/FXX/COMMUNE',
      'year'=> 2003,
    ],
  ];

  // [ name => path ]
  static function paths(): array {
    $paths = [];
    foreach (self::DATASETS as $name => $dataset)
      $paths[$name] = $dataset['path'];
    return $paths;
  }

  // liste des datasets [ name => year ] des référentiels des communes simples
  static function yearsNotR(): array {
    $years = [];
    foreach (self::DATASETS as $name => $dataset)
      if (!isset($dataset['rattachées']))
        $years[$name] = $dataset['year'];
    return $years;
  }
  
  // retourne le référentiel de COMS de $datasets le plus récent valide entre 2 dates $begin et $end, $end non compris
  static function between(array $datasets, string $begin, string $end): string {
    foreach ($datasets as $dataset) {
      $years = self::yearsNotR();
      if (!($year = $years[$dataset] ?? null)) continue;
      if ((strcmp($begin, "$year-01-01") <= 0) && (strcmp("$year-01-01", $end) < 0))
        return $dataset;
    }
    return '';
  }
  
  // retourne le référentiel de communes simples le plus récent antérieur à $dvref ou daté de $dvref
  static function mostRecentEarlierCSDataset(string $dref, array $datasets): string {
    foreach ($datasets as $dataset) {
      $years = self::yearsNotR();
      if (!($year = $years[$dataset] ?? null)) continue;
      if (strcmp("$year-01-01", $dref) <= 0)
        return $dataset;
    }
    return '';
  }
};

//print_r(Datasets::years());