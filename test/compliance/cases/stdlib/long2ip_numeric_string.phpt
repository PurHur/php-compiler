--TEST--
stdlib long2ip() numeric string coercion (#9298, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(long2ip('4294967295'));
echo "\n";
var_export(long2ip('2130706433'));
echo "\n";
--EXPECT--
'255.255.255.255'
'127.0.0.1'
