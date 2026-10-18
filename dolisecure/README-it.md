# DOLISECURE PER [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Funzionalità

 - Verifica che la versione di Dolibarr installata non sia interessata da alcuna vulnerabilità (CVE) nota, interrogando il database pubblico NVD (National Vulnerability Database, NIST).
 - Mostra un indicatore, con icona e colore adattato al livello di gravità (critico, alto, medio, basso), in un widget della pagina iniziale, attivato per impostazione predefinita all'attivazione del modulo.
 - Esegue automaticamente una nuova verifica, al massimo una volta al giorno (intervallo configurabile), qualunque sia la pagina di Dolibarr consultata - senza dipendere dal corretto funzionamento dello scheduler (cron).
 - Invia automaticamente un'email agli amministratori non appena viene rilevata una nuova vulnerabilità, indicando, quando l'informazione è disponibile, la versione di Dolibarr che corregge il difetto.
 - Offre una pagina di resoconto dettagliata che elenca ogni vulnerabilità (identificativo CVE, gravità, versione che corregge il difetto, data di pubblicazione, link alla scheda NVD).

![Screenshot dolisecure](img/logo.png?raw=true "DoliSecure")

Altri moduli sono disponibili su [Dolistore.com](https://www.dolistore.com/index.php?controller=search&search_query=joliciel).



## Per ottenere assistenza

Per ottenere assistenza su questo modulo, potete scrivere a: <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.

Potete anche porre la vostra domanda sul <a href="https://www.dolibarr.fr/forum" target="_new">Forum Dolibarr</a>.



## Traduzioni

Il modulo è disponibile in francese, inglese, spagnolo, tedesco e italiano.

Le traduzioni possono essere completate manualmente modificando i file presenti nelle directory `langs` del modulo.



## Installazione

Prerequisiti: è necessario disporre di un'installazione di Dolibarr ERP & CRM. Potete scaricarlo da [Dolibarr.org](https://www.dolibarr.org), oppure ottenere un'istanza pronta all'uso nel cloud su https://saas.dolibarr.org.

### Dal file ZIP e dall'interfaccia grafica

- Se avete ottenuto il modulo in un file zip denominato `module_dolisecure-x.x.x.zip` (ad esempio scaricandolo da un marketplace come [Dolistore](https://www.dolistore.com/index.php?controller=search&search_query=joliciel)):
	- fate clic su ```Home - Configurazione - Moduli - Distribuisci/Installa un modulo esterno```
	- e inviate il file
	- facendo clic sul pulsante ```Sfoglia``` e poi su ```Invia```.

Nota: se questa schermata indica che non esiste alcuna directory personalizzata, verificate che la configurazione sia corretta:

- Nella directory di installazione di Dolibarr, modificate il file ```htdocs/conf/conf.php``` e verificate che le seguenti righe non siano commentate:
	```php
	//$dolibarr_main_url_root_alt ...
	//$dolibarr_main_document_root_alt ...
	```

- Decommentatele se necessario (rimuovete il carattere ```//```) e assegnate il percorso file corretto in base alla vostra installazione di Dolibarr.

	Ad esempio:

	- su Linux:
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
		```

	- su Windows:
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
		```

### Fase finale

Nel vostro browser:

  - Accedete a Dolibarr come super amministratore.
  - Andate su ```Home - Configurazione - Moduli```.
  - Dovreste ora poter trovare e attivare il modulo 'DoliSecure'.

Una volta attivato, il widget di sicurezza compare automaticamente nella pagina iniziale di tutti gli utenti, e viene eseguita una prima verifica non appena un utente connesso consulta la pagina successiva (oppure dalla pagina di configurazione del modulo, tramite il pulsante "Verifica ora").



## Licenze

### Codice principale

Questo modulo è sviluppato e distribuito da <a href="https://joliciel.fr" target="_new">Joliciel</a>.

Il codice sorgente è liberamente utilizzabile e modificabile nel rispetto della licenza
![GPLv3 logo](img/gplv3.png) GPLv3, o (a vostra scelta) qualsiasi versione successiva. Consultate il file COPYING per maggiori informazioni.

### Documentazione

Tutti i testi e i file readme sono sotto licenza [GFDL](https://www.gnu.org/licenses/fdl-1.3.en.html).



## Per saperne di più

Altri moduli sono disponibili su <a href="https://www.dolistore.com/index.php?controller=search&search_query=joliciel" target="_new">Dolistore.com</a>.

Per ottenere un nuovo modulo, realizzato appositamente per voi, scrivete a: <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.



## Contribuire

### Per proporre i vostri miglioramenti

Creare una nuova versione (-> doc/backup):
1) incrementare il numero di versione in `core/modules/modDoliSecure.class.php`
2) aggiungere il numero di versione e una descrizione in `ChangeLog.md`
3) `git add . ; git commit -m "laDescrizione" ; git push`
4) `git tag 1.0.1 ; git push --tags`
