--TEST--
stdlib long2ip() numeric string coercion JIT (#9298, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(long2ip('4294967295'));
echo "\n";
--EXPECT--
'255.255.255.255'
