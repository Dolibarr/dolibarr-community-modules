# CloudBackup — configuration

CloudBackup sauvegarde la base et les documents de Dolibarr vers un bucket S3, un serveur FTP/FTPS ou
SFTP, ou un répertoire du serveur, selon un planning ou à la demande, et les restaure depuis Dolibarr.
Il fonctionne sur hébergement mutualisé : ni shell, ni binaire, ni paquet système. Cette page couvre
l'installation et chaque réglage ; [usage.md](usage.md) couvre sauvegardes, restaurations et reprise
après sinistre.

## 1. Prérequis

| | |
|---|---|
| Dolibarr | 18 ou plus récent |
| PHP | 7.1 ou plus récent, 64 bits |
| Base | MySQL ou MariaDB (le dump PHP de Dolibarr ne gère pas PostgreSQL) |
| Module | *Travaux planifiés* (Cron), activé automatiquement avec CloudBackup |

Chaque stockage et chaque format demande une extension PHP. La page de configuration montre ce que le
serveur possède et grise ce qu'il ne peut pas faire :

| Choix | Demande | Présent en mutualisé |
|---|---|---|
| Format restic | openssl, PHP 64 bits | oui |
| Format archives | zip, zlib | oui |
| S3 | curl | oui |
| FTP | ftp | oui |
| FTPS | curl compilé avec FTPS | oui |
| SFTP | ssh2 | rarement : demander à l'hébergeur, ou utiliser S3/FTPS |
| Répertoire du serveur | — | oui |

![Vérification du serveur](../img/setup-server-check.png)

## 2. Installation

1. Installer le module depuis les modules communautaires de Dolibarr (*Accueil > Configuration >
   Modules > Déployer/installer un module externe*), ou copier le dossier `cloudbackup` dans
   `htdocs/custom/`.
2. *Accueil > Configuration > Modules* : activer **CloudBackup** (famille *Base*). Le travail planifié est
   créé **désactivé** : on l'active à la fin de la configuration, une fois le test de connexion réussi.
3. Donner les droits (*Accueil > Utilisateurs > un utilisateur > Permissions > CloudBackup*) :

| Id | Droit |
|---|---|
| 9504011 | Voir les sauvegardes et leur historique |
| 9504012 | Lancer une sauvegarde |
| 9504021 | Restaurer une sauvegarde — remplace la base et les documents |
| 9504031 | Configurer les sauvegardes, vérifier le stockage, retirer les verrous |

Un administrateur les a toujours tous. Le menu est *Outils > Cloud backup*, et pour les administrateurs
aussi *Accueil > Outils d'administration > Cloud backup*.

![Droits](../img/permissions.png)

## 3. La page de configuration

*Accueil > Configuration > Modules > CloudBackup* (roue dentée). Elle a quatre blocs.

![Page de configuration](../img/setup-s3.png)

### Ce serveur

Ce que le serveur sait faire, la plus grosse table de la base et la mémoire qu'une sauvegarde demande.
Le dump PHP de Dolibarr lit la plus grosse table d'un coup en mémoire (mesuré : environ sa taille en
PHP 8, le double en PHP 7) ; une table de 300 Mo demande donc plus de 300 Mo de `memory_limit`.

- **coche** : c'est bon, ou l'hébergeur laisse le module relever la limite le temps de la sauvegarde
  (il le fait seul) ;
- **avertissement** : l'hébergeur l'interdit. Demander plus de mémoire, ou lancer les sauvegardes par le
  travail planifié : le PHP en ligne de commande de l'hébergeur n'a souvent pas de limite.

`max_execution_time` ne concerne que le bouton *Sauvegarder maintenant* : une grosse instance peut
dépasser la durée permise à une requête web. Le travail planifié n'a pas de limite de temps.

### Destination

**Format**

- **Dépôt restic** (recommandé). Chiffré (AES-256 + Poly1305), dédupliqué : après la première sauvegarde,
  seul ce qui a changé est envoyé, et les documents inchangés ne sont même pas relus. Le dépôt suit le
  format de l'outil [restic](https://restic.net), qui sait le lire, le vérifier et le restaurer sans
  Dolibarr.
- **Archives simples**. Chaque sauvegarde est un dossier avec le dump SQL gzippé, un zip des documents et
  un `manifest.json`, découpés en parts de 16 Mo. Lisibles sans aucun outil, mais **non chiffrées** et
  envoyées en entier à chaque fois.

**Stockage**, puis ses réglages d'accès, puis **Sous-chemin de l'instance** (*Sub-path for this instance*) :
un répertoire, ou un préfixe de clés dans un bucket, dans le stockage. Un sous-chemin par instance
Dolibarr : un sous-chemin contient un dépôt.

#### S3 (AWS, Scaleway, OVH, Wasabi, Backblaze B2, MinIO…)

| Champ | Exemple (Scaleway, Paris) |
|---|---|
| Endpoint | `https://s3.fr-par.scw.cloud` |
| Région | `fr-par` |
| Bucket | `ma-societe-sauvegardes` (à créer d'abord dans la console du fournisseur) |
| Clé d'accès / clé secrète | la clé d'API d'un compte limité à ce bucket |
| URL en style « virtual host » | décoché, sauf si le fournisseur exige `https://bucket.endpoint/` |

Autres endpoints : OVH `https://s3.gra.io.cloud.ovh.net` (région `gra`), AWS
`https://s3.eu-west-3.amazonaws.com` (région `eu-west-3`), Backblaze B2
`https://s3.eu-central-003.backblazeb2.com` (région `eu-central-003`), Wasabi
`https://s3.eu-central-1.wasabisys.com`.

Donner à la clé les droits minimaux : lire, écrire, supprimer et lister les objets de ce seul bucket.
**Ne pas activer le versioning ni un verrou d'objets** sur le bucket sans savoir pourquoi : la rétention
du module supprime des objets, un bucket versionné les garderait (et les facturerait).

*Endpoint sur un réseau privé* autorise un endpoint à adresse IP privée (un MinIO du réseau local). C'est
décoché par défaut : Dolibarr refuse les adresses privées contre la falsification de requêtes (SSRF).

#### FTP / FTPS

![Réglages FTP](../img/setup-ftp.png)

Hôte, port (21), utilisateur, mot de passe. Le sous-chemin est relatif au répertoire d'accueil du compte FTP.

- **FTPS** (TLS explicite) chiffre le mot de passe et les transferts : à utiliser dès que le serveur le
  propose. Il passe par l'extension curl de PHP (le seul client PHP qui fonctionne avec tous les serveurs
  FTPS).
- **Vérifier le certificat du serveur** : laisser coché. Ne décocher que pour un serveur à certificat
  auto-signé de confiance.

En FTP simple, le mot de passe circule en clair : l'associer au format restic, au moins les données
elles-mêmes sont chiffrées.

#### SFTP

![Réglages SFTP](../img/setup-sftp.png)

Hôte, port (22), utilisateur, mot de passe. Le sous-chemin est relatif au répertoire d'accueil, sauf
s'il commence par `/`. Demande l'extension PHP ssh2 : sans elle, le choix est grisé.

#### Répertoire du serveur

**Répertoire local** (*Local directory*) : un chemin absolu **hors** du répertoire documents de Dolibarr (le module refuse un chemin dedans : une
sauvegarde ne doit pas disparaître avec les données qu'elle protège). Utile pour un second disque ou un
répertoire synchronisé par un autre outil ; ne protège pas de la perte du serveur. Les sauvegardes vont
dans `Répertoire local/Sous-chemin de l'instance`.

Sur un hébergement mutualisé, le chemin absolu est rarement connu : l'aide du champ affiche le répertoire
documents, et la page suggère un répertoire à côté, hors de la racine web (sous cPanel, le répertoire
personnel du compte : `/home/<compte>/cloudbackups`) ; *Use it* remplit le champ. L'enregistrement
vérifie le chemin, crée le répertoire et vérifie que PHP peut y écrire ; un répertoire servi par le
serveur web est accepté avec un avertissement, car n'importe qui devinant son adresse pourrait
télécharger les sauvegardes. Les sauvegardes se téléchargent ensuite depuis la page *Sauvegardes*, sans
accès FTP.

### Chiffrement

**Mot de passe du dépôt** (format restic) : 12 caractères au moins. Il chiffre les sauvegardes.

> **Garder une copie de ce mot de passe hors de Dolibarr** (gestionnaire de mots de passe, coffre). Si le
> serveur est perdu, le mot de passe stocké dans sa base part avec lui, et sans lui personne ne peut
> relire les sauvegardes — vous non plus.

Changer le mot de passe ensuite ne rechiffre pas un dépôt existant : pour un autre mot de passe, prendre
un autre chemin.

### Contenu

| Option | |
|---|---|
| Base de données | le dump de l'outil de sauvegarde de Dolibarr, version PHP (pas besoin de `mysqldump`) |
| Répertoire documents | sans fichiers temporaires, logs, aperçus, anciens dumps ni `install.lock`, comme l'outil de sauvegarde de Dolibarr |
| Modules externes | le code de `htdocs/custom` (les liens symboliques ne sont pas suivis) |
| conf.php | format restic seulement, stocké chiffré, jamais restauré automatiquement. Son `$dolibarr_main_instance_unique_id` déchiffre les mots de passe stockés en base : une restauration sur un nouveau serveur en a besoin |
| Chemins exclus | un motif par ligne, relatif au répertoire documents : `ecm/videos/*`, `*/gros-*.zip` |

### Rétention

Après chaque sauvegarde, le module supprime les sauvegardes que les règles ne gardent pas, **pour cette
instance seulement**, puis libère la place (*prune*) : les données qu'aucune sauvegarde restante n'utilise
sont supprimées, les packs à moitié utilisés sont réécrits.

| Règle | Garde |
|---|---|
| N dernières | les N sauvegardes les plus récentes |
| N quotidiennes / hebdomadaires / mensuelles / annuelles | la plus récente de chacun des N derniers jours / semaines / mois / années qui en ont une |

Les règles s'additionnent. Les valeurs par défaut (7 dernières, 14 quotidiennes, 8 hebdomadaires,
12 mensuelles) gardent environ un an d'historique. Quand une règle a moins de périodes que demandé, la
plus ancienne sauvegarde est gardée en plus, comme le fait restic. Le format archives n'a que
*N dernières*.

![Rétention](../img/setup-retention.png)

## 4. Tester, puis planifier

1. **Enregistrer**, puis **Tester la connexion** : le module écrit un objet, le relit et le supprime.
2. **Sauvegarder maintenant** dans l'onglet *Sauvegardes*, et lire le journal.
3. **Modifier le planning** : ouvre le travail planifié *CloudBackup: back up the database and the
   documents*. L'activer, régler sa fréquence (quotidienne pour commencer) et sa première exécution (la
   nuit).

Les travaux planifiés de Dolibarr tournent quand quelque chose appelle leur lanceur. En mutualisé,
ajouter une tâche cron dans le panneau de l'hébergeur (cPanel > *Tâches Cron* chez o2switch), toutes les
5 ou 15 minutes, avec **une** de ces lignes :

```sh
# ligne de commande : pas de limite de temps, la méthode recommandée
php /home/compte/www/dolibarr/scripts/cron/cron_run_jobs.php CRON_KEY admin

# ou un appel d'URL, si le panneau n'exécute que des URL (une requête web a une limite de temps)
wget -q -O /dev/null "https://erp.exemple.fr/public/cron/cron_run_jobs_by_url.php?securitykey=CRON_KEY&userlogin=admin"
```

`CRON_KEY` est la clé de sécurité affichée dans *Accueil > Configuration > Modules > Travaux planifiés*.
La page de configuration de CloudBackup affiche les deux lignes avec les bons chemins.

![Planning](../img/cron-job.png)
