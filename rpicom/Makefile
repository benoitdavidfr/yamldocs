# Génération du Rpicom et de certains états avec vérification / à leur schéma plus vérification du schéma evec exemples
# Mis à jour le 21/4/2020
#
# les ojectifs sans extension Yaml correspondent à la vérification du fichier Yaml / son schéma
all: rpicom fcoms
fcoms: com20200101 com20100101 com20000101 com1943
# Vérif. de rpicom / son schéma
rpicom: rpicom.yaml exrpicom
	php index.php check rpicom.yaml
# Vérif. de exrpicom
exrpicom: exrpicom.yaml
	php index.php check exrpicom.yaml
# génération de rpicom.yaml
rpicom.yaml: com20200101.yaml index.php grpmvts.inc.php base.inc.php
	php index.php brpicom
# Vérif. de exfcoms
exfcoms: exfcoms.yaml
	php index.php check exfcoms.yaml
# Vérif. de com20200101 / son schéma exfcoms
com20200101: com20200101.yaml exfcoms
	php index.php check com20200101.yaml
# génération de com20200101.yaml
com20200101.yaml: communes2020.csv index.php
	php index.php buildState 2020-01-01 communes2020.csv csv > $@
com20100101: com20100101.yaml exfcoms
	php index.php check com20100101.yaml
com20100101.yaml: France2010.txt index.php
	php index.php buildState 2010-01-01 France2010.txt txt > $@
com20000101: com20000101.yaml exfcoms
	php index.php check com20000101.yaml
com20000101.yaml: France2000.txt index.php
	php index.php buildState 2000-01-01 France2000.txt txt > $@
com1943: com1943.yaml exfcoms
	php index.php check com1943.yaml
com1943.yaml: index.php
	php index.php genCom1943 > $@
