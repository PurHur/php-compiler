--TEST--
Language: union param widened on override accepted (zend_inheritance.c, #25632)
--FILE--
<?php
class A { public function f(int $x): void {} }
class B extends A { public function f(int|string $x): void {} }
echo "ok\n";
--EXPECT--
ok
