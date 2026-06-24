<?php

/** Issue #11105 — fgetcsv($fp, length: 0) named second parameter. */

$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);
$row = fgetcsv($f, length: 0);
var_export($row);
echo "\n";
fclose($f);
