# Dolibarr modules development tools

This directory is not a directory of a module.
It contains tools used to validate modules, or generate indexes or zip files of all other modules...


== To regenate the global index file ==
'dev/build/makepack-modules.php' index


== To regenerate the zip files ==
'dev/build/makepack-modules.php' makezip


== To run phpunit tests ==
- Go into the root dir of a Dolibarr version.
- Set the environment variable export DOLIBARR_HTDOCS=/fullpathofrootdir
- Run the command: phpunit 'einvoicing/test/phpunit/AllTest.php'
