--TEST--
stdlib settype($array,'string') — Array to string conversion warning (#10293, ext/standard/type.c)
--FILE--
<?php
$a = [1, 2];
settype($a, 'string');
var_export($a);
echo "\n";
var_export(str_contains((string) (error_get_last()['message'] ?? ''), 'Array to string conversion'));
echo "\n";
--EXPECTF--
PHP Warning:  Array to string conversion in %s on line %d
'Array'
true
