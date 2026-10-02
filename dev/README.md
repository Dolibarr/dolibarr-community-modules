# Dolibarr modules development tools

This directory is not a directory of a module.
It contains tools used to validate modules, or generate indexes or zip files of all other modules, or publish them...


== To regenate the global index file ==
'dev/build/makepack-modules.php' index


== To regenerate the zip files ==
'dev/build/makepack-modules.php' makezip modulename


== To regenerate the zip files, the ChangeLog and set the tag (Make a release) ==
'dev/build/makepack-modules.php' makeziptag modulename


== To push new released package on dolistore ==
'dev/build/makepack-modules.php' psuhdolistore modulename


== To run phpunit tests ==
- Go into the root dir of a Dolibarr version.
- Set the environment variable export DOLIBARR_HTDOCS=/fullpathofdolibarrrootdir
- Run the command: phpunit 'einvoicing/test/phpunit/AllTest.php'
