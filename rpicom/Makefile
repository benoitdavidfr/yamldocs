all: com20200101.yaml com1943.yaml
com20200101.yaml: communes2020.csv index.php
	php index.php buildState 2020-01-01 communes2020.csv csv > $@ ; php index.php check $@
com1943.yaml: index.php
	php index.php genCom1943 > $@ ; php index.php check $@
