--TEST--
Language: int backed enum case fetch returns object (#5514, zend_enum.c)
--FILE--
<?php
enum E: int { case A = 1; }
echo 'gettype: ', gettype(E::A), "\n";
var_export(E::A instanceof E);
echo "\n";
var_export(is_a(E::A, E::class));
echo "\n";
function f($x = E::A) { return gettype($x); }
echo 'default: ', f(), "\n";
--EXPECT--
gettype: object
true
true
default: object
