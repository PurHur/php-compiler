--TEST--
stdlib fgetcsv() after fputcsv([]) returns one NULL field (#5243, ext/standard/file.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
fputcsv($fp, []);
rewind($fp);
$row = fgetcsv($fp);
var_export($row);
echo "\n";
echo fgetcsv($fp) === false ? 'eof' : 'more', "\n";
fclose($fp);
--EXPECT--
array (
  0 => NULL,
)
eof
