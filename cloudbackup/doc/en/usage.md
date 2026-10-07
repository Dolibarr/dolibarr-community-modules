# CloudBackup — usage

How to run backups, read their history, restore, and recover a Dolibarr lost with its server. The
settings are described in [configuration.md](configuration.md).

## 1. The Backups page

*Tools > Cloud backup* (or *Home > Admin tools > Cloud backup*).

![Backups page](../img/backups-page.png)

- **Status**: format, storage, date of the last successful backup. A warning sign appears when the last
  success is older than two days, or when a requirement is missing (extension, password, memory).
  **Space used on the storage** is measured after each backup and each check (listing a remote storage
  takes time); with restic it also gives the data the backups hold, so the gain of deduplication shows.
  The free space is not shown: PHP sees the disk of the server, not the quota of a shared hosting.
- **Back up now** (permission *Run a backup*): runs a backup in the page and shows its log. On a big
  instance prefer the scheduled job, a web request has a time limit.
- **Show all backups**: lists the backups present on the storage. With plain archives the 5 most recent
  show at once; with restic the list waits for this button (opening the repository derives its key,
  a few seconds).
- **Check the storage** / **Check and read all data** (permission *Configure*): see §4.
- **Remove the locks** (restic, permission *Configure*): see §5.
- **History**: every backup, restore and interruption, with its duration, size, amount sent and log
  (the *i* icon). The last 5 rows; *Show the whole history* shows the rest.

![Log of a backup](../img/backup-log.png)

A backup always:

1. dumps the database with the PHP backup tool of Dolibarr (MySQL/MariaDB);
2. stores the dump, the documents (and optionally `htdocs/custom` and `conf.php`) on the storage — with
   restic, a file whose size and date did not change since the last backup is not read again, and the
   dump is cut on its own lines so a few changed rows only send the chunks around them;
3. applies the retention and frees the space.

Only one operation runs at a time on an instance. A process killed by the host (time or memory limit)
closes its run as *Interrupted* and releases its lock.

## 2. List the backups

![Backups on the storage](../img/backups-list.png)

The list shows every backup of the storage: date, identifier, instance that made it, Dolibarr version,
content, size. Backups of **another instance** sharing the same path are listed too (and marked), so a
new server can restore the backups of the old one. A warning sign next to the version means the backup
comes from another Dolibarr version: after restoring its database, run the upgrade of Dolibarr
(`/install/`).

With the setup right, the *Download* column gives the files of each backup, read from the storage and
sent as they come, with no copy on the server: no FTP access needed on shared hosting. An archive gives
its files as stored (`<base>.sql.gz`, `documents.zip`, `custom.zip`); a restic backup gives its dump
(`.sql`) and its directories as `.tar` (opened by Windows 10 and later, macOS, Linux, 7-Zip). These files
hold the whole database in clear: keep them safe. Each download is written in the history.

## 3. Restore

Permission *Restore a backup*. Click **Restore** on a line of the list.

![Restore](../img/restore-confirm.png)

- **Database**: the database is **replaced** by the one of the backup: everything recorded since is
  lost. Tables the backup does not have (a module installed since) are kept. The history of the
  backups is kept as it is.
- **Documents**: the files of the backup are written back; a file already identical (same size and
  date) is left alone. Files created since the backup are **kept**: the restored database may not know
  them any more, they become orphans. The log counts them and the *Orphans* tab lists them.
- **Delete the documents the backup does not have** (unchecked by default): gives back exactly the
  documents of the backup. Excluded paths (temporary files, logs, `install.lock`, excluded patterns)
  are never touched, and external modules are never deleted. The safety backup still holds what goes.
- **External modules**: the code of `htdocs/custom`, from the backup.
- **Back up the current state first** (checked by default): a backup of the present state runs before,
  so a restore of the wrong backup can itself be undone. Keep it checked unless the present state is
  known to be worthless.

The database is restored first. A document that cannot be written (owner, permissions, quota) does not
stop the others: the log lists it and the restore ends in error so it is noticed.

![Restore log](../img/restore-log.png)

After restoring a database, log out and in again: the session may refer to rows that changed.

### Orphans

The *Orphans* tab (permission *Configure the backups*) has three lists. Each file can be previewed,
downloaded or deleted, one by one or with the mass action.

- **Documents without object**: files in the directory of an invoice, order, proposal, contract, third
  party or social contribution that no longer exists in the database. Same rule as the repair tool of
  Dolibarr (`install/repair.php`, *clean orphan directories*), for the current entity.
- **File index without file**: lines of the file index of Dolibarr (`ecm_files`) whose file is gone.
  Deleting one only removes the line, as *clean_ecm_files_table* of the repair tool does.
- **Kept by the last restore**: the documents the last restore found and the backup did not have.

![Orphans](../img/orphans-files.png)

![Kept by the last restore](../img/orphans-kept.png)

A new object after a restore can take a number an orphan directory holds (an invoice numbered again
after the restore of an older database): look at the orphans before creating new documents.

## 4. Check the backups

- **Check the storage**: every pack the index knows exists, every snapshot can be read and refers to
  existing data (restic), every part of every archive is there (archives).
- **Check and read all data** (restic): also downloads every pack and verifies its hash. Takes as long
  as a full download; do it from time to time.

A backup that was never restored is a hope, not a backup: restore one now and then on a test instance.

## 5. Locks

A restic repository is locked while a backup or a prune writes to it. A lock older than 30 minutes is
ignored, and a process that dies releases its lock. **Remove the locks** is for the remaining case, a
crash of the server during a backup; do it only when no backup runs anywhere on this repository.

## 6. Disaster recovery: restore on a new server

The server is gone; you have the storage credentials and the repository password (kept outside
Dolibarr, see the configuration).

1. Install the **same major version** of Dolibarr as the backup (or older, then upgrade), with an empty
   database.
2. Install and enable CloudBackup. In its setup, enter the **same storage, path and password**.
3. *Backups > Show all backups*: the backups of the old server are listed (other instance). Restore
   **Database** and **Documents** (and *External modules* if you backed them up), without the safety
   backup since there is nothing to save.
4. Put back the unique id of the old `conf.php`, or the passwords stored in the database (mail, API,
   bank…) stay unreadable. With restic it is in the backup:

   ```sh
   restic -r <repository> dump latest /conf/conf.php > old-conf.php
   ```

   Copy the value of `$dolibarr_main_instance_unique_id` into the new `htdocs/conf/conf.php`.
5. Log in with an account of the old database.

## 7. Restore without Dolibarr

### restic format, with the restic tool

```sh
export RESTIC_REPOSITORY=s3:https://s3.fr-par.scw.cloud/my-company-backups/dolibarr
export AWS_ACCESS_KEY_ID=... AWS_SECRET_ACCESS_KEY=... RESTIC_PASSWORD=...
restic snapshots                                   # the backups
restic ls latest /database                         # the dump is /database/<database name>.sql
restic dump latest /database/dolibarr.sql | mysql dolibarr
restic restore latest --target /tmp/restore --include /documents
restic check --read-data                           # verify everything
```

Other storages: `RESTIC_REPOSITORY=/path` for a directory, `sftp:user@host:path` for SFTP. restic has
no FTP backend; `rclone serve restic` can expose a FTP storage to it.

The repository is in restic format 1, uncompressed: every restic version reads it, and restic can back
up into it too. Do not run `restic migrate upgrade_repo_v2` on it: compressed data needs the zstd PHP
extension to be read back by the module.

### Archive format, with no tool

```sh
cat documents.zip.part* > documents.zip && unzip documents.zip -d /path/to/documents
cat dolibarr.sql.gz.part* | gunzip | mysql dolibarr
```

`manifest.json` gives the size and the SHA-256 of each file.

## 8. Troubleshooting

| Symptom | Cause, fix |
|---|---|
| *Allowed memory size exhausted* during the dump | the biggest table does not fit in `memory_limit` (see *This server* in the setup): more memory, or the scheduled job in command line |
| The backup stops after a few minutes from the button | web time limit of the host: use the scheduled job |
| *Wrong password: no key of the repository opens with it* | the password of the setup is not the one of the repository at this path |
| *The repository is locked by …* | another backup runs, or a crash left a lock: wait 30 min or *Remove the locks* |
| *Error bad hostname IP (private or reserved range)* | S3 endpoint on a private network: tick *Endpoint on a private network* |
| *SignatureDoesNotMatch* | wrong secret key, or wrong region |
| *needs php-ssh2* | SFTP is not possible on this server: use S3 or FTPS |
| *compressed data (restic format 2)* | the repository was created or migrated by restic with compression: let the module create a new one at another path, or install the zstd PHP extension |
| *A backup or a restore is already running* | wait for it; a run killed by the host is closed automatically, else *Remove the locks* closes it |

## 9. Known limits

- PostgreSQL is not supported (the PHP dump of Dolibarr is MySQL only).
- The PHP dump of Dolibarr writes the text value `-0` as a number: it comes back as `0`. This is a
  behaviour of the core dump, not of the module.
- File and directory names that Dolibarr itself refuses or renames (they contain `< > ? * | " ° $ ; `` ` `` ~`,
  `..` or `--`) are neither backed up nor restored: Dolibarr never creates such names. They are skipped
  with a warning in the log. Names that are not valid UTF-8 are skipped the same way (restic format).
- Stored procedures, triggers and views are not in the PHP dump of Dolibarr (it only dumps tables).
