--TEST--
Language: (bool) cast on unset local is false (#5421, zend_operators.c)
--FILE--
<?php
$x = 1;
unset($x);
var_dump((bool) $x);
var_dump(isset($x));
--EXPECT--
bool(false)
bool(false)
