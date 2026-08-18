--TEST--
Language: inherited static properties share declaring-class storage (#32301, zend_inheritance.c)
--FILE--
<?php
class A { public static $x = 42; }
class B extends A {}
var_dump(B::$x);
A::$x = 7;
var_dump(B::$x);
B::$x = 9;
var_dump(A::$x);
?>
--EXPECT--
int(42)
int(7)
int(9)
