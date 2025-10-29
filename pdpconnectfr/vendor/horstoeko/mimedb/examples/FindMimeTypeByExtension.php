<?php

use horstoeko\mimedb\MimeDb;

error_reporting(E_ERROR | E_WARNING | E_PARSE);

require dirname(__FILE__) . "/../vendor/autoload.php";

$mimeDb = MimeDb::singleton();

// OUTPUT:
//   application/vnd.openxmlformats-officedocument.wordprocessingml.document

echo $mimeDb->findFirstMimeTypeByExtension('.docx') . PHP_EOL;

// OUTPUT:
//   application/vnd.openxmlformats-officedocument.wordprocessingml.document

foreach ($mimeDb->findAllMimeTypesByExtension('.docx') as $mimetype) {
    echo $mimetype . PHP_EOL;
}
