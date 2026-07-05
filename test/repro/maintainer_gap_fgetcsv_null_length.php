<?php
/** Issue #16493 — fgetcsv($fp, null, …) must read unlimited like Zend (ext/standard/file.c). */
$fp = fopen('php://memory', 'r+');
fwrite($fp, "a,b\n");
rewind($fp);
$row = fgetcsv($fp, null, ',');
var_export($row);
echo "\n";
fclose($fp);
