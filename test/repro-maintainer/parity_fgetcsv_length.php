<?php

$fp = fopen('php://memory', 'r+');
fwrite($fp, "a,b\n");
rewind($fp);
var_export(fgetcsv($fp, '0'));
echo "\n";
rewind($fp);
var_export(fgetcsv($fp, '1024'));
echo "\n";
rewind($fp);
var_export(fgetcsv($fp, 1024.7));
echo "\n";
fclose($fp);
