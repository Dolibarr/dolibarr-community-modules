# DOLISECURE FÜR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Funktionen

 - Prüft, ob die installierte Dolibarr-Version von einer bekannten Sicherheitslücke (CVE) betroffen ist, durch Abfrage der öffentlichen NVD-Datenbank (National Vulnerability Database, NIST).
 - Zeigt einen Indikator mit Symbol und einer dem Schweregrad entsprechenden Farbe (kritisch, hoch, mittel, niedrig) in einem Widget auf der Startseite an, das bei Aktivierung des Moduls standardmäßig aktiviert ist.
 - Führt automatisch eine neue Prüfung durch, höchstens einmal pro Tag (Frist konfigurierbar), unabhängig davon, welche Dolibarr-Seite aufgerufen wird - ohne von der korrekten Funktion des Aufgabenplaners (Cron) abhängig zu sein.
 - Sendet automatisch eine E-Mail an die Administratoren, sobald eine neue Sicherheitslücke erkannt wird, und gibt dabei, wenn verfügbar, die Dolibarr-Version an, die die Lücke behebt.
 - Bietet eine detaillierte Berichtsseite mit allen Sicherheitslücken (CVE-Kennung, Schweregrad, Version, die die Lücke behebt, Veröffentlichungsdatum, Link zum NVD-Eintrag).

![Screenshot dolisecure](img/logo.png?raw=true "DoliSecure")

Weitere Module sind auf [Dolistore.com](https://www.dolistore.com/index.php?controller=search&search_query=joliciel) verfügbar.



## Hilfe erhalten

Um Hilfe zu diesem Modul zu erhalten, schreiben Sie an: <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.

Sie können Ihre Frage auch im <a href="https://www.dolibarr.fr/forum" target="_new">Dolibarr-Forum</a> stellen.



## Übersetzungen

Das Modul ist auf Französisch, Englisch, Spanisch, Deutsch und Italienisch verfügbar.

Übersetzungen können manuell vervollständigt werden, indem die Dateien in den `langs`-Verzeichnissen des Moduls bearbeitet werden.



## Installation

Voraussetzung: Sie benötigen eine installierte Dolibarr-ERP&CRM-Software. Sie können sie von [Dolibarr.org](https://www.dolibarr.org) herunterladen oder eine gebrauchsfertige Instanz in der Cloud unter https://saas.dolibarr.org erhalten.

### Über die ZIP-Datei und die Benutzeroberfläche

- Wenn Sie das Modul als fertige ZIP-Datei mit dem Namen `module_dolisecure-x.x.x.zip` erhalten haben (z. B. beim Herunterladen von einem Marktplatz wie [Dolistore](https://www.dolistore.com/index.php?controller=search&search_query=joliciel)):
	- klicken Sie auf ```Start - Konfiguration - Module - Externes Modul bereitstellen/installieren```
	- und laden Sie die Datei hoch
	- indem Sie auf ```Durchsuchen``` und dann auf ```Senden``` klicken.

Hinweis: Wenn dieser Bildschirm anzeigt, dass kein benutzerdefiniertes Verzeichnis vorhanden ist, überprüfen Sie, ob Ihre Konfiguration korrekt ist:

- Bearbeiten Sie in Ihrem Dolibarr-Installationsverzeichnis die Datei ```htdocs/conf/conf.php``` und stellen Sie sicher, dass folgende Zeilen nicht auskommentiert sind:
	```php
	//$dolibarr_main_url_root_alt ...
	//$dolibarr_main_document_root_alt ...
	```

- Kommentieren Sie sie bei Bedarf ein (entfernen Sie das Zeichen ```//```) und geben Sie den korrekten Dateipfad entsprechend Ihrer Dolibarr-Installation an.

	Zum Beispiel:

	- unter Linux:
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
		```

	- unter Windows:
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
		```

### Letzter Schritt

In Ihrem Browser:

  - Melden Sie sich als Super-Administrator bei Dolibarr an.
  - Gehen Sie zu ```Start - Konfiguration - Module```.
  - Sie sollten nun das Modul 'DoliSecure' finden und aktivieren können.

Nach der Aktivierung erscheint das Sicherheits-Widget automatisch auf der Startseite aller Benutzer, und eine erste Prüfung wird durchgeführt, sobald ein angemeldeter Benutzer die nächste Seite aufruft (oder über die Konfigurationsseite des Moduls mit der Schaltfläche "Jetzt prüfen").



## Lizenzen

### Hauptcode

Dieses Modul wird von <a href="https://joliciel.fr" target="_new">Joliciel</a> entwickelt und vertrieben.

Der Quellcode kann unter Einhaltung der Lizenz
![GPLv3 logo](img/gplv3.png) GPLv3 oder (nach Ihrer Wahl) einer späteren Version frei verwendet und verändert werden. Weitere Informationen finden Sie in der Datei COPYING.

### Dokumentation

Alle Texte und Readme-Dateien stehen unter der Lizenz [GFDL](https://www.gnu.org/licenses/fdl-1.3.en.html).



## Weitere Informationen

Weitere Module sind auf <a href="https://www.dolistore.com/index.php?controller=search&search_query=joliciel" target="_new">Dolistore.com</a> verfügbar.

Um ein neues, speziell für Sie entwickeltes Modul zu erhalten, schreiben Sie an: <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.



## Mitwirken

### Um Ihre Verbesserungen vorzuschlagen

Eine neue Version erstellen (-> doc/backup):
1) die Versionsnummer in `core/modules/modDoliSecure.class.php` erhöhen
2) die Versionsnummer und eine Beschreibung in `ChangeLog.md` hinzufügen
3) `git add . ; git commit -m "dieBeschreibung" ; git push`
4) `git tag 1.0.1 ; git push --tags`
