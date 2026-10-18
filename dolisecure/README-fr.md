# DOLISECURE POUR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Fonctionnalités

 - Vérifie que la version de Dolibarr installée n'est concernée par aucune vulnérabilité (CVE) connue, en interrogeant la base de données publique NVD (National Vulnerability Database, NIST).
 - Affiche un indicateur, avec icône et couleur adaptée au niveau de dangerosité (critique, élevé, moyen, faible), sur un widget de la page d'accueil, activé par défaut dès l'activation du module.
 - Effectue une nouvelle vérification automatiquement, au maximum une fois par jour (délai configurable), quelle que soit la page de Dolibarr consultée - sans dépendre du bon fonctionnement du planificateur de tâches (cron).
 - Envoie automatiquement un e-mail aux administrateurs dès qu'une nouvelle vulnérabilité est détectée, en indiquant, lorsque l'information est disponible, la version de Dolibarr qui corrige la faille.
 - Propose une page de compte-rendu détaillée listant chaque vulnérabilité (identifiant CVE, sévérité, version corrigeant la faille, date de publication, lien vers la fiche NVD).

![Screenshot dolisecure](img/logo.png?raw=true "DoliSecure")

D'autres modules sont disponibles sur [Dolistore.com](https://www.dolistore.com/index.php?controller=search&search_query=joliciel).



## Pour obtenir de l'aide

Pour obtenir de l'aide sur ce module, vous pouvez écrire à : <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.

Vous pouvez également poser votre question sur le <a href="https://www.dolibarr.fr/forum" target="_new">Forum Dolibarr</a>.



## Traductions

Le module est disponible en français, anglais, espagnol, allemand et italien.

Les traductions peuvent être complétées manuellement en modifiant les fichiers présents dans les répertoires `langs` du module.



## Installation

Prérequis : vous devez disposer d'une installation de Dolibarr ERP & CRM. Vous pouvez le télécharger depuis [Dolibarr.org](https://www.dolibarr.org), ou obtenir une instance prête à l'emploi dans le cloud sur https://saas.dolibarr.org.

### À partir du fichier ZIP et de l'interface graphique

- Si vous avez obtenu le module dans un fichier zip nommé `module_dolisecure-x.x.x.zip` (comme lors d'un téléchargement depuis une place de marché telle que [Dolistore](https://www.dolistore.com/index.php?controller=search&search_query=joliciel)) :
	- veuillez cliquer sur ```Accueil - Configuration - Modules - Déployer/Installer un module externe```
	- et envoyer le fichier
	- en cliquant sur le bouton ```Parcourir``` puis ```Envoyer```.

Remarque : si cet écran vous indique qu'il n'y a pas de répertoire personnalisé, vérifiez que votre configuration est correcte :

- Dans votre répertoire d'installation Dolibarr, éditez le fichier ```htdocs/conf/conf.php``` et vérifiez que les lignes suivantes ne sont pas commentées :
	```php
	//$dolibarr_main_url_root_alt ...
	//$dolibarr_main_document_root_alt ...
	```

- Décommentez-les si nécessaire (supprimez le caractère ```//```) et attribuez le chemin de fichiers correct en fonction de votre installation de Dolibarr.

	Par exemple :

	- sous Linux :
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
		```

	- sous Windows :
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
		```

### Étape finale

Dans votre navigateur :

  - Connectez-vous à Dolibarr en tant que super administrateur.
  - Allez dans ```Accueil - Configuration - Modules```.
  - Vous devriez maintenant pouvoir trouver et activer le module 'DoliSecure'.

Une fois activé, le widget de sécurité apparaît automatiquement sur la page d'accueil de tous les utilisateurs, et une première vérification est effectuée dès la prochaine page consultée par un utilisateur connecté (ou depuis la page de configuration du module, via le bouton "Vérifier maintenant").



## Licences

### Code principal

Ce module est développé et diffusé par <a href="https://joliciel.fr" target="_new">Joliciel</a>.

Le code source est librement utilisable et modifiable dans le respect de la licence
![GPLv3 logo](img/gplv3.png) GPLv3, ou (à votre choix) toute version ultérieure. Voir le fichier COPYING pour plus d'informations.

### Documentation

Tous les textes et fichiers readme sont sous licence [GFDL](https://www.gnu.org/licenses/fdl-1.3.fr.html).



## Pour aller plus loin

D'autres modules sont disponibles sur <a href="https://www.dolistore.com/index.php?controller=search&search_query=joliciel" target="_new">Dolistore.com</a>.

Pour obtenir un nouveau module, réalisé spécialement pour vous, écrivez à : <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.



## Contribution

### Pour proposer vos améliorations

Faire une nouvelle version (-> doc/backup) :
1) lancer build/buildzip.php # → /tmp/module_dolisecure-X.Y.Z.zip
2) copier /tmp/module_dolisecure-X.Y.Z.zip dans ../dev/build/bin/
3) ajouter le n° de version et une description dans `ChangeLog.md`
4) incrémenter le n° de version dans `index.yaml`
5) incrémenter le n° de version dans `core/modules/modDoliSecure.class.php`
6) `git add . ; git commit -m "laDescription" ; git push`
7) `git tag 1.0.10 ; git push --tags`
   