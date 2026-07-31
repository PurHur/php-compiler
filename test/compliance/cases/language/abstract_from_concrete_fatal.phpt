--TEST--
Language: cannot make concrete parent method abstract (zend_inheritance.c, #25660)
--FILE--
<?php
class A { public function f(): void {} }
abstract class B extends A { abstract public function f(): void; }
echo "LOADED\n";
--EXPECTF--
Fatal error: Cannot make non abstract method A::f() abstract in class B in %s on line %d
--EXPECT_EXIT--
255
