--TEST--
Language: union param narrowed on override rejected (zend_inheritance.c, #25632)
--FILE--
<?php
class A { public function f(int|string $x): void {} }
class B extends A { public function f(int $x): void {} }
echo "accepted\n";
--EXPECT_EXIT--
255
