# DOLISECURE FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Features

 - Checks that the installed Dolibarr version is not affected by any known vulnerability (CVE), by querying the public NVD database (National Vulnerability Database, NIST).
 - Shows an indicator, with an icon and a color adapted to the danger level (critical, high, medium, low), in a widget on the home page, enabled by default as soon as the module is activated.
 - Automatically performs a new check, at most once a day (configurable delay), regardless of which Dolibarr page is viewed - without relying on the task scheduler (cron) actually running.
 - Automatically sends an email to administrators as soon as a new vulnerability is detected, indicating, when the information is available, the Dolibarr version that fixes the flaw.
 - Provides a detailed report page listing every vulnerability (CVE identifier, severity, version that fixes the flaw, publication date, link to the NVD entry).

![Screenshot dolisecure](img/logo.png?raw=true "DoliSecure")

Other external modules are available on [Dolistore.com](https://www.dolistore.com/index.php?controller=search&search_query=joliciel).



## Getting help

To get help on this module, you can write to: <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.

You can also ask your question on the <a href="https://www.dolibarr.fr/forum" target="_new">Dolibarr Forum</a>.



## Translations

The module is available in French, English, Spanish, German and Italian.

Translations can be completed manually by editing the files in the module's `langs` directories.



## Installation

Prerequisites: You must have Dolibarr ERP & CRM software installed. You can download it from [Dolibarr.org](https://www.dolibarr.org), or get a ready-to-use instance in the cloud at https://saas.dolibarr.org.

### From the ZIP file and GUI interface

- If you got the module as a ready-to-deploy zip file named `module_dolisecure-x.x.x.zip` (e.g., when downloading it from a marketplace like [Dolistore](https://www.dolistore.com/index.php?controller=search&search_query=joliciel)):
	- go to ```Home - Setup - Modules - Deploy/Install an external module```
	- and upload the file
	- by clicking the ```Browse``` button, then ```Send```.

Note: if this screen tells you there is no custom directory, check that your configuration is correct:

- In your Dolibarr installation directory, edit the file ```htdocs/conf/conf.php``` and check that the following lines are not commented out:
	```php
	//$dolibarr_main_url_root_alt ...
	//$dolibarr_main_document_root_alt ...
	```

- Uncomment them if needed (remove the ```//``` characters) and set the correct file path according to your Dolibarr installation.

	For example:

	- on Linux:
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
		```

	- on Windows:
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
		```

### Final steps

Using your browser:

  - Log into Dolibarr as a super-administrator.
  - Go to ```Home - Setup - Modules```.
  - You should now be able to find and enable the 'DoliSecure' module.

Once activated, the security widget automatically appears on the home page of every user, and a first check is performed as soon as a logged-in user views the next page (or from the module's setup page, using the "Check now" button).



## Licenses

### Main code

This module is developed and distributed by <a href="https://joliciel.fr" target="_new">Joliciel</a>.

The source code is freely usable and modifiable under the
![GPLv3 logo](img/gplv3.png) GPLv3 license, or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readme's are licensed under [GFDL](https://www.gnu.org/licenses/fdl-1.3.en.html).



## Going further

Other modules are available on <a href="https://www.dolistore.com/index.php?controller=search&search_query=joliciel" target="_new">Dolistore.com</a>.

To get a new module, built specially for you, write to: <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.



## Contributing

### To propose your improvements

Making a new version (-> doc/backup):
1) increment the version number in `core/modules/modDoliSecure.class.php`
2) add the version number and a description in `ChangeLog.md`
3) `git add . ; git commit -m "theDescription" ; git push`
4) `git tag 1.0.1 ; git push --tags`
