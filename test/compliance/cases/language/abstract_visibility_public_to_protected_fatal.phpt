--TEST--
Language: protected impl of public abstract rejected (zend_inheritance.c, #25662)
--FILE--
<?php
abstract class A { abstract public function f(): void; }
class B extends A { protected function f(): void {} }
echo "LOADED\n";
--EXPECTF--
Fatal error: Access level to B::f() must be public (as in class A) in %s on line %d
--EXPECT_EXIT--
255
