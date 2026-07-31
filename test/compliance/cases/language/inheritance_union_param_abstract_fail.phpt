--TEST--
Language: abstract union param narrowed on override rejected (zend_inheritance.c, #25632)
--FILE--
<?php
abstract class A { abstract public function f(int|string $x): void; }
class B extends A { public function f(int $x): void {} }
echo "ok\n";
--EXPECT_EXIT--
255
