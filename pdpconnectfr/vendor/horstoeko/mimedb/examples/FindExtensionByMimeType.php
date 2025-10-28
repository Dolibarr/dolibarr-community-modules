<?php

use horstoeko\mimedb\MimeDb;

error_reporting(E_ERROR | E_WARNING | E_PARSE);

require dirname(__FILE__) . "/../vendor/autoload.php";

$mimeDb = MimeDb::singleton();

// OUTPUT:
//   docx

echo $mimeDb->findFirstFileExtensionByMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document') . PHP_EOL;

// OUTPUT:
//   mkv
//   mk3d
//   mks

foreach ($mimeDb->findAllFileExtensionsByMimeType('video/x-matroska') as $fileExtension) {
    echo $fileExtension . PHP_EOL;
}

// OUTPUT:
//   docx

foreach ($mimeDb->findAllFileExtensionsByMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document') as $fileExtension) {
    echo $fileExtension . PHP_EOL;
}
