--TEST--
stdlib array_pop()/array_shift() — E_WARNING on empty array (#4791, ext/standard/array.c)
--FILE--
<?php
$a = [];
var_export(array_pop($a));
echo "\n";
$b = [];
var_export(array_shift($b));
echo "\n";
--EXPECT--
PHP Warning:  array_pop(): Trying to pop an empty array
PHP Warning:  array_shift(): Trying to shift an empty array
NULL
NULL
