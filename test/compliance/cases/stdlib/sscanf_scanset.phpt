--TEST--
stdlib sscanf() — %[scanset] conversion (#11281, ext/standard/formatted_io.c)
--FILE--
<?php
declare(strict_types=1);

var_export(sscanf('abc123', '%[a-z]'));
echo "\n";
var_export(sscanf('abc123', '%[a-c]'));
echo "\n";
var_export(sscanf('xyz', '%[^0-9]'));
echo "\n";
var_export(sscanf('abc123', '%[a-z]%d'));
echo "\n";
--EXPECT--
array (
  0 => 'abc',
)
array (
  0 => 'abc',
)
array (
  0 => 'xyz',
)
array (
  0 => 'abc',
  1 => 123,
)
