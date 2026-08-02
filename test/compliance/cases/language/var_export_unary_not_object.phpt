--TEST--
Language: unary ! on object as var_export() arg (zend_operators.c, #26702)
--FILE--
<?php
$o = new stdClass();
echo var_export(!$o, true), "\n";
$b = !$o;
echo var_export($b, true), "\n";
echo var_export((bool)$o, true), "\n";
echo gettype(!$o), "\n";
--EXPECT--
false
false
true
boolean
