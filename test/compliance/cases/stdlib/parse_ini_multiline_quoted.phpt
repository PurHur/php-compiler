--TEST--
stdlib parse_ini_string() double-quoted multiline values (#13030, ext/standard/ini.c)
--FILE--
<?php
$ini = "x = \"line1\nline2\"\n";
var_export(parse_ini_string($ini));
echo "\n";
var_export(parse_ini_string('y = "hello"'));
--EXPECT--
array (
  'x' => 'line1
line2',
)
array (
  'y' => 'hello',
)
