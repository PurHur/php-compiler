--TEST--
stdlib str_getcsv()/fgetcsv() empty escape disables quoted escapes (#24561)
--FILE--
<?php
$s = "a,\"b\\\"c\",d";
var_export(str_getcsv($s, ',', '"', ''));
echo "\n";
$f = fopen('php://memory', 'r+');
fwrite($f, $s."\n");
rewind($f);
var_export(fgetcsv($f, 0, ',', '"', ''));
echo "\n";
var_export(str_getcsv('a\\,b', ',', '"', ''));
echo "\n";
var_export(str_getcsv('"ab"c,d', ',', '"', '\\'));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b\\c"',
  2 => 'd',
)
array (
  0 => 'a',
  1 => 'b\\c"',
  2 => 'd',
)
array (
  0 => 'a\\',
  1 => 'b',
)
array (
  0 => 'abc',
  1 => 'd',
)
