--TEST--
stdlib parse_ini_file() JIT — runtime path (#30756, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
$path = sys_get_temp_dir() . '/phpc-jit-parse-ini-file.ini';
file_put_contents($path, "a=1\nb=2\n");
var_export(parse_ini_file($path));
echo "\n";
@unlink($path);
var_export(@parse_ini_file('/no/such/phpc-30756.ini'));
echo "\n";
--EXPECT--
array (
  'a' => '1',
  'b' => '2',
)
false
