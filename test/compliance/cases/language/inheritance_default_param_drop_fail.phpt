--TEST--
Language: child dropping parent default rejected (zend_inheritance.c, #26520)
--INI--
display_errors=1
--FILE--
<?php
class A { public function f(int $x = 1) {} }
class B extends A { public function f(int $x) {} }
echo "accepted\n";
--EXPECTF--

Fatal error: Declaration of B::f(int $x) must be compatible with A::f(int $x = 1) in %s on line %d
--EXPECT_EXIT--
255
