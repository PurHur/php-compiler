--TEST--
AOT parse_ini_file() — compile-time path + missing file (#30756)
--FILE--
<?php
$parsed = parse_ini_file('test/fixtures/aot/cases/parse_ini_file.ini');
echo $parsed['a'], "\n";
$missing = @parse_ini_file('/no/such/phpc-30756.ini');
echo false === $missing ? "false\n" : "not-false\n";
--EXPECT--
1
false
