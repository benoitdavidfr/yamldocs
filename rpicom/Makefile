all: rpicom com20200101 com1943
rpicom: rpicom.yaml exrpicom.yaml
	php index.php check rpicom.yaml
rpicom.yaml: com20200101.yaml index.php grpmvts.inc.php base.inc.php
	php index.php brpicom
com20200101: com20200101.yaml exfcoms.yaml
	php index.php check com20200101.yaml
com20200101.yaml: communes2020.csv index.php grpmvts.inc.php base.inc.php
	php index.php buildState 2020-01-01 communes2020.csv csv > $@
com1943: com1943.yaml exfcoms.yaml
	php index.php check com1943.yaml
com1943.yaml: index.php grpmvts.inc.php base.inc.php
	php index.php genCom1943 > $@
