--TEST--
stdlib parse_ini_string() JIT — compile-time INI literal (#3263)
--JIT--
--FILE--
<?php
$ini = "x=1\ny=2";
var_export(parse_ini_string($ini));
echo "\n";
--EXPECT--
array (
  'x' => '1',
  'y' => '2',
)
