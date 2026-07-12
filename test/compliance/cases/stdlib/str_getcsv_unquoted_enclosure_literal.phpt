--TEST--
stdlib str_getcsv()/fgetcsv() unquoted enclosure literals (#18209, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

echo var_export(str_getcsv('a""b,c', ',', '"', '\\'), true), "\n";
$handle = fopen('php://memory', 'r+');
fwrite($handle, "a\"\"b,c\n");
rewind($handle);
echo var_export(fgetcsv($handle, separator: ',', enclosure: '"', escape: '\\'), true), "\n";
fclose($handle);
echo var_export(str_getcsv('"a""b",c', ',', '"', '\\'), true), "\n";
--EXPECT--
array (
  0 => 'a""b',
  1 => 'c',
)
array (
  0 => 'a""b',
  1 => 'c',
)
array (
  0 => 'a"b',
  1 => 'c',
)
