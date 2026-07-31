--TEST--
Language: public impl of protected abstract allowed (zend_inheritance.c, #25662)
--FILE--
<?php
abstract class A { abstract protected function f(): void; }
class B extends A { public function f(): void {} }
echo "LOADED\n";
--EXPECT--
LOADED
