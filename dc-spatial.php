<?php
if (isset($ydcheckWriteAccessForPhpCode))
  return ['benoit'];

if (!isset($_SERVER['PATH_INFO'])) {
  echo '<pre>$_SERVER='; print_r($_SERVER); echo "</pre>\n";
}
$param = substr($_SERVER['PATH_INFO'], strlen('/dc-spatial/'));
//echo "param=$param\n";
if (!$param) { // sans paramètre renvoie de page d'explication
  return(JsonSch::file_get_contents(__DIR__.'/dc-spatial-admin.yaml'));
}
$zones = [
  'World'=> ['westlimit'=>-180,'southlimit'=>-90,'eastlimit'=>180,'northlimit'=>90],
  'FX'=> ['name'=>'France métropolitaine', 'westlimit'=>-5.16, 'southlimit'=>41.33, 'eastlimit'=>9.57, 'northlimit'=>51.09],
  'FX.ZEE'=> ['name'=>'France métropolitaine (ZEE)', 'westlimit'=>-9.93, 'southlimit'=>41.24, 'eastlimit'=>10.22, 'northlimit'=>51.56],
  'GP'=> ['name'=>'Guadeloupe', 'westlimit'=>-61.81, 'southlimit'=>15.83, 'eastlimit'=>-61.00, 'northlimit'=>16.52],
  'GP.ZEE'=> ['name'=>'Guadeloupe (ZEE)', 'westlimit'=>-62.82, 'southlimit'=>15.06, 'eastlimit'=>-57.53, 'northlimit'=>18.57],
  'MQ'=> ['name'=>'Martinique', 'westlimit'=>-61.24, 'southlimit'=>14.38, 'eastlimit'=>-60.80, 'northlimit'=>14.89],
  'MQ.ZEE'=> ['name'=>'Martinique (ZEE)', 'westlimit'=>-62.82, 'southlimit'=>14.11, 'eastlimit'=>-57.53, 'northlimit'=>16.49],
  'GF'=> ['name'=>'Guyane', 'westlimit'=>-54.61, 'southlimit'=>2.11, 'eastlimit'=>-51.63, 'northlimit'=>5.75],
  'GF.ZEE'=> ['name'=>'Guyane (ZEE)', 'westlimit'=>-54.61, 'southlimit'=>2.11, 'eastlimit'=>-49.41, 'northlimit'=>8.84],
  'RE'=> ['name'=>'La Réunion', 'westlimit'=>55.21, 'southlimit'=>-21.40, 'eastlimit'=>55.84, 'northlimit'=>-20.87],
  'RE.ZEE'=> ['name'=>'La Réunion (ZEE)', 'westlimit'=>51.79, 'southlimit'=>-24.74, 'eastlimit'=>58.23, 'northlimit'=>-18.28],
  'YT'=> ['name'=>'Mayotte', 'westlimit'=>44.95, 'southlimit'=>-13.08, 'eastlimit'=>45.31, 'northlimit'=>-12.58],
  'YT.ZEE'=> ['name'=>'Mayotte (ZEE)', 'westlimit'=>43.48, 'southlimit'=>-14.53, 'eastlimit'=>46.69, 'northlimit'=>-11.13],
  'PM'=> ['name'=>'Saint-Pierre-et-Miquelon', 'westlimit'=>-56.52, 'southlimit'=>46.74, 'eastlimit'=>-56.11, 'northlimit'=>47.15],
  'PM.ZEE'=> ['name'=>'Saint-Pierre-et-Miquelon (ZEE)', 'westlimit'=>-57.10, 'southlimit'=>43.41, 'eastlimit'=>-56.10, 'northlimit'=>47.37],
  'BL'=> ['name'=>'Saint-Barthélémy', 'westlimit'=>-62.96, 'southlimit'=>17.87, 'eastlimit'=>-62.78, 'northlimit'=>17.93],
  'BL.ZEE'=> ['name'=>'Saint-Barthélémy (ZEE)', 'westlimit'=>-63.11, 'southlimit'=>17.64, 'eastlimit'=>-62.22, 'northlimit'=>18.32],
  'MF'=> ['name'=>'Saint-Martin', 'westlimit'=>-63.16, 'southlimit'=>18.04, 'eastlimit'=>-62.97, 'northlimit'=>18.13],
  'MF.ZEE'=> ['name'=>'Saint-Martin (ZEE)', 'westlimit'=>-63.64, 'southlimit'=>17.87, 'eastlimit'=>-62.73, 'northlimit'=>18.19],
  'TF'=> [
    ['name'=>'Îles Saint-Paul et Nouvelle-Amsterdam', 'westlimit'=>77.50, 'southlimit'=>-38.74, 'eastlimit'=>77.60, 'northlimit'=>-37.79],
    ['name'=>'Îles Saint-Paul et Nouvelle-Amsterdam (ZEE)', 'westlimit'=>73.22, 'southlimit'=>-42.08, 'eastlimit'=>81.81, 'northlimit'=>-34.44],
    ['name'=>'Archipel Crozet', 'westlimit'=>50.15, 'southlimit'=>-46.48, 'eastlimit'=>52.33, 'northlimit'=>-45.95],
    ['name'=>'Archipel Crozet (ZEE)', 'westlimit'=>45.35, 'southlimit'=>-49.82, 'eastlimit'=>57.16, 'northlimit'=>-42.59],
    ['name'=>'Îles Kerguelen', 'westlimit'=>68.42, 'southlimit'=>-50.02, 'eastlimit'=>70.56, 'northlimit'=>-48.45],
    ['name'=>'Îles Kerguelen (ZEE)', 'westlimit'=>63.28, 'southlimit'=>-53.17, 'eastlimit'=>75.64, 'northlimit'=>-45.10],
    ['name'=>'Terre-Adélie', 'westlimit'=>-, 'southlimit'=>, 'eastlimit'=>-, 'northlimit'=>],
    ['name'=>'Atoll Bassas da India', 'westlimit'=>-, 'southlimit'=>, 'eastlimit'=>-, 'northlimit'=>],
    ['name'=>'Île Europa', 'westlimit'=>-, 'southlimit'=>, 'eastlimit'=>-, 'northlimit'=>],
    ['name'=>'Îles Glorieuses', 'westlimit'=>-, 'southlimit'=>, 'eastlimit'=>-, 'northlimit'=>],
    ['name'=>'Île Juan de Nova', 'westlimit'=>-, 'southlimit'=>, 'eastlimit'=>-, 'northlimit'=>],
    ['name'=>'Île Tromelin', 'westlimit'=>-, 'southlimit'=>, 'eastlimit'=>-, 'northlimit'=>],
  ],
  'PF'=> ['Polynésie française', 'westlimit'=>-152, 'southlimit'=>-18, 'eastlimit'=>-149, 'northlimit'=>-15],
  'WF'=> ['Wallis-et-Futuna', 'westlimit'=>-178.5, 'southlimit'=>-14.5, 'eastlimit'=>-175.5, 'northlimit'=>-13.17],
  'NC'=> ['Nouvelle-Calédonie', 'westlimit'=>163.5, 'southlimit'=>-23, 'eastlimit'=>168.25, 'northlimit'=>-19.36],
  'CP'=> ['Île Clipperton', ], // ???
];

if (!isset($zones[$param]))
  return [$param => "paramètre $param inconnu"];
elseif (!isset($zones[$param]['name']))
  return [$param => array_merge(['name'=>$param], $zones[$param])];
else
  return [$param => $zones[$param]];

