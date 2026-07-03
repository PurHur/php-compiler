--TEST--
stdlib str_getcsv()/fgetcsv() escape=enclosure doubled-quote unescaping (#9303, ext/standard/file.c)
--FILE--
<?php
$line = 'a,"b""c",d';
echo var_export(str_getcsv($line, ',', '"', '"'), true), "\n";
$f = fopen('php://memory', 'r+');
fwrite($f, $line . "\n");
rewind($f);
echo var_export(fgetcsv($f, 0, ',', '"', '"'), true), "\n";
fclose($f);
echo var_export(str_getcsv('"a""b",c', ',', '"', '"'), true), "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b"c',
  2 => 'd',
)
array (
  0 => 'a',
  1 => 'b"c',
  2 => 'd',
)
array (
  0 => 'a"b',
  1 => 'c',
)
