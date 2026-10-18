# DOLISECURE PARA [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Funcionalidades

 - Comprueba que la versión de Dolibarr instalada no esté afectada por ninguna vulnerabilidad (CVE) conocida, consultando la base de datos pública NVD (National Vulnerability Database, NIST).
 - Muestra un indicador, con icono y color adaptado al nivel de gravedad (crítico, alto, medio, bajo), en un widget de la página de inicio, activado por defecto al activar el módulo.
 - Realiza una nueva comprobación automáticamente, como máximo una vez al día (plazo configurable), sea cual sea la página de Dolibarr consultada - sin depender del correcto funcionamiento del planificador de tareas (cron).
 - Envía automáticamente un correo a los administradores en cuanto se detecta una nueva vulnerabilidad, indicando, cuando la información está disponible, la versión de Dolibarr que corrige el fallo.
 - Ofrece una página de informe detallada que lista cada vulnerabilidad (identificador CVE, gravedad, versión que corrige el fallo, fecha de publicación, enlace a la ficha NVD).

![Screenshot dolisecure](img/logo.png?raw=true "DoliSecure")

Hay otros módulos disponibles en [Dolistore.com](https://www.dolistore.com/index.php?controller=search&search_query=joliciel).



## Para obtener ayuda

Para obtener ayuda sobre este módulo, puede escribir a: <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.

También puede plantear su pregunta en el <a href="https://www.dolibarr.fr/forum" target="_new">Foro Dolibarr</a>.



## Traducciones

El módulo está disponible en francés, inglés, español, alemán e italiano.

Las traducciones se pueden completar manualmente editando los archivos presentes en los directorios `langs` del módulo.



## Instalación

Requisitos previos: debe disponer de una instalación de Dolibarr ERP & CRM. Puede descargarlo desde [Dolibarr.org](https://www.dolibarr.org), o bien obtener una instancia lista para usar en la nube en https://saas.dolibarr.org.

### A partir del archivo ZIP y de la interfaz gráfica

- Si ha obtenido el módulo en un archivo zip llamado `module_dolisecure-x.x.x.zip` (como al descargarlo desde un mercado como [Dolistore](https://www.dolistore.com/index.php?controller=search&search_query=joliciel)):
	- haga clic en ```Inicio - Configuración - Módulos - Desplegar/Instalar un módulo externo```
	- y envíe el archivo
	- haciendo clic en el botón ```Examinar``` y luego en ```Enviar```.

Nota: si esta pantalla le indica que no hay ningún directorio personalizado, compruebe que su configuración sea correcta:

- En su directorio de instalación de Dolibarr, edite el archivo ```htdocs/conf/conf.php``` y compruebe que las siguientes líneas no estén comentadas:
	```php
	//$dolibarr_main_url_root_alt ...
	//$dolibarr_main_document_root_alt ...
	```

- Descoméntelas si es necesario (elimine el carácter ```//```) y asigne la ruta de archivos correcta según su instalación de Dolibarr.

	Por ejemplo:

	- en Linux:
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
		```

	- en Windows:
		```php
		$dolibarr_main_url_root_alt = '/custom';
		$dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
		```

### Paso final

En su navegador:

  - Conéctese a Dolibarr como super administrador.
  - Vaya a ```Inicio - Configuración - Módulos```.
  - Ahora debería poder encontrar y activar el módulo 'DoliSecure'.

Una vez activado, el widget de seguridad aparece automáticamente en la página de inicio de todos los usuarios, y se realiza una primera comprobación en cuanto un usuario conectado consulta la siguiente página (o desde la página de configuración del módulo, mediante el botón "Comprobar ahora").



## Licencias

### Código principal

Este módulo está desarrollado y distribuido por <a href="https://joliciel.fr" target="_new">Joliciel</a>.

El código fuente puede utilizarse y modificarse libremente respetando la licencia
![GPLv3 logo](img/gplv3.png) GPLv3, o (a su elección) cualquier versión posterior. Consulte el archivo COPYING para más información.

### Documentación

Todos los textos y archivos readme están bajo licencia [GFDL](https://www.gnu.org/licenses/fdl-1.3.en.html).



## Para ir más lejos

Hay otros módulos disponibles en <a href="https://www.dolistore.com/index.php?controller=search&search_query=joliciel" target="_new">Dolistore.com</a>.

Para obtener un nuevo módulo, desarrollado especialmente para usted, escriba a: <a href="mailto:dolibarr@joliciel.fr">dolibarr@joliciel.fr</a>.



## Contribución

### Para proponer sus mejoras

Crear una nueva versión (-> doc/backup):
1) incrementar el número de versión en `core/modules/modDoliSecure.class.php`
2) añadir el número de versión y una descripción en `ChangeLog.md`
3) `git add . ; git commit -m "laDescripción" ; git push`
4) `git tag 1.0.1 ; git push --tags`
