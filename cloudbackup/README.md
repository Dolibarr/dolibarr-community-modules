# CloudBackup for [Dolibarr ERP & CRM](https://www.dolibarr.org)

Scheduled backup and restore of a Dolibarr instance — the database and the documents — to a
S3 bucket, a FTP/FTPS or SFTP server, or a directory, **from Dolibarr itself**.

It is made for shared hosting: no shell, no system package, no binary to install. Everything runs
in PHP with extensions every host provides (curl, openssl, ftp, zip).

## Documentation

- Configuration: [English](doc/en/configuration.md) · [Français](doc/fr/configuration.md)
- Usage, restore, disaster recovery: [English](doc/en/usage.md) · [Français](doc/fr/usage.md)

![Backups page](doc/img/backups-page.png)

## What is backed up

- **The database**, dumped by the backup tool of Dolibarr in its PHP flavour (the one that needs no
  `mysqldump`). MySQL and MariaDB.
- **The documents directory**, without temporary files, logs and previews, as the backup tool of
  Dolibarr does.
- Optionally the **external modules** (`htdocs/custom`) and, in restic format, **`conf.php`**: its
  unique id is what decrypts the passwords stored in the database, a restore on a new server needs it.

## Two formats

**restic repository** (default). The backups are encrypted (AES-256 + Poly1305) and deduplicated:
after the first one, only what changed is sent — unchanged documents are not even read again, and the
SQL dump is cut on its own lines so that a few changed rows only send the chunks around them. The
repository follows the [restic](https://restic.net) format, implemented in PHP by the module: the
`restic` command line tool can list, check, restore and prune it, and back up into it too. Keep the
repository password outside Dolibarr: without it, nobody can read the backups.

**Plain archives**. Each backup is a folder with a gzipped SQL dump, a zip of the documents and a
`manifest.json`, cut in 16 MB parts (`cat documents.zip.part* > documents.zip`). Readable without any
tool, not encrypted.

## Scheduling

The module adds a scheduled job, disabled until you enable it (Home > Setup > Scheduled jobs). On a
shared hosting, the jobs of Dolibarr run when the cron task of the hosting panel calls
`scripts/cron/cron_run_jobs.php`, or when an external service calls `public/cron/cron_run_jobs_by_url.php`.

## Restore

The *Backups* page lists the backups of the storage and restores the database, the documents and the
external modules of any of them, after a backup of the current state. The database is replaced;
documents of the backup overwrite the current ones, files created since are kept.

A restore on a new server: install Dolibarr, install this module, give it the same storage and the same
password, restore. Then put back the `$dolibarr_main_instance_unique_id` of the saved `conf.php`.

From the command line, with restic:

```sh
export RESTIC_REPOSITORY=s3:https://s3.fr-par.scw.cloud/my-bucket/my-path RESTIC_PASSWORD=...
export AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=...
restic snapshots
restic dump latest /database/dolibarr.sql | mysql dolibarr
restic restore latest --target /tmp/restore --include /documents
```

## Permissions

| Id | Permission |
|---|---|
| 9504011 | See the backups and their history |
| 9504012 | Run a backup |
| 9504021 | Restore a backup |
| 9504031 | Configure the backups |

## Limits

- PostgreSQL is not supported: the PHP dump of Dolibarr is MySQL only.
- The PHP dump reads each table in memory: a huge table needs a matching `memory_limit`. The setup page
  estimates it and warns; the module raises the limit itself when the host allows it.
- restic repositories created by restic 0.14+ with compression (format 2) need the PHP extension `zstd`
  to be read. Let the module create the repository, or create it with
  `restic init --repository-version 1`.
- The PHP dump of Dolibarr writes the text value `-0` as a number: it comes back as `0`.
- A web request has a time limit: on big instances, run the backups from the scheduled job.

## Licenses

GPLv3 or (at your option) any later version. See file COPYING for more information.
