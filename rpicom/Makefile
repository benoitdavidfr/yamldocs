# Génération du Rpicom et de certains états avec vérification / à leur schéma plus vérification du schéma evec exemples
# Mis à jour le 21/4/2020
#
# les ojectifs sans extension Yaml correspondent à la vérification du fichier Yaml / son schéma
all: rpicom fcoms
clean:
	rm -f rpicom.yaml com20200101.yaml com20190101.yaml com20180101.yaml com20170101.yaml \
      com20100101.yaml com20000101.yaml com19430101.yaml
fcoms: com20200101 com20190101 com20180101 com20170101 com20100101 com20000101 com19430101
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
# 2019
com20190101: com20190101.yaml exfcoms
	php index.php check com20190101.yaml
com20190101.yaml: communes-01012019.csv index.php
	php index.php buildState 2019-01-01 communes-01012019.csv csv > $@
# 2018
com20180101: com20180101.yaml exfcoms
	php index.php check com20180101.yaml
com20180101.yaml: France2018.txt index.php
	php index.php buildState 2018-01-01 France2018.txt txt > $@
# 2017
com20170101: com20170101.yaml exfcoms
	php index.php check com20170101.yaml
com20170101.yaml: France2017.txt index.php
	php index.php buildState 2017-01-01 France2017.txt txt > $@
# 2016
com20160101: com20160101.yaml exfcoms
	php index.php check com20160101.yaml
com20160101.yaml: Corhistoriq2016.txt index.php
	php index.php buildState 2016-01-01 Corhistoriq2016.txt txt > $@
# 2010
com20100101: com20100101.yaml exfcoms
	php index.php check com20100101.yaml
com20100101.yaml: France2010.txt index.php
	php index.php buildState 2010-01-01 France2010.txt txt > $@
# 2000
com20000101: com20000101.yaml exfcoms
	php index.php check com20000101.yaml
com20000101.yaml: France2000.txt index.php
	php index.php buildState 2000-01-01 France2000.txt txt > $@
# 1943
com19430101: com19430101.yaml exfcoms
	php index.php check com19430101.yaml
com19430101.yaml: index.php
	php index.php genCom1943 > $@
