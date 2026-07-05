--TEST--
JIT: str_getcsv() — scalar-to-string coercion for argument #1 (#4318, ext/standard/file.c)
--FILE--
<?php
var_export(str_getcsv(123));
echo "\n";
var_export(str_getcsv(1.5));
echo "\n";
var_export(str_getcsv(true));
echo "\n";
--EXPECT--
array (
  0 => '123',
)
array (
  0 => '1.5',
)
array (
  0 => '1',
)
