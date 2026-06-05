--TEST--
stdlib ini_alter() — alias of ini_set() (#6085, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(function_exists('ini_alter'));
echo "\n";
var_export(function_exists('ini_set'));
echo "\n";
$old = ini_alter('error_reporting', '0');
echo is_string($old) ? "alter-ok\n" : "alter-bad\n";
echo ini_alter('not_a_real_ini_option', 'x') === false ? "unknown-false\n" : "unknown-bad\n";
echo ini_set('error_reporting', $old) === '0' ? "roundtrip-ok\n" : "roundtrip-bad\n";
--EXPECT--
true
true
alter-ok
unknown-false
roundtrip-ok
