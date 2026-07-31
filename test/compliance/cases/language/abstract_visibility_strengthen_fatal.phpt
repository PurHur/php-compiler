--TEST--
Language: private impl of protected abstract rejected (zend_inheritance.c, #25662)
--FILE--
<?php
abstract class A { abstract protected function f(): void; }
class B extends A { private function f(): void {} }
echo "LOADED\n";
--EXPECTF--
Fatal error: Access level to B::f() must be protected (as in class A) or weaker in %s on line %d
--EXPECT_EXIT--
255
