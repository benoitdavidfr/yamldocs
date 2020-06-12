-- exemples de requêtes PostgreSql - pense-bête

-- tous les chiffres entre 1 et 100 (step 1 par défaut)
SELECT generate_series(1,100);

-- Extracting a subset of points from a 3d multipoint
SELECT n, ST_AsEWKT(ST_GeometryN(the_geom, n)) As geomewkt
FROM (
VALUES (ST_GeomFromEWKT('MULTIPOINT(1 2 7, 3 4 7, 5 6 7, 8 9 10)') ),
( ST_GeomFromEWKT('MULTICURVE(CIRCULARSTRING(2.5 2.5,4.5 2.5, 3.5 3.5), (10 11, 12 11))') )
	)As foo(the_geom)
	CROSS JOIN generate_series(1,100) n
WHERE n <= ST_NumGeometries(the_geom);
