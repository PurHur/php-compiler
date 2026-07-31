--TEST--
Language: union param narrowed on implements rejected (zend_inheritance.c, #25632)
--FILE--
<?php
interface I { public function f(int|string $x): void; }
class C implements I { public function f(int $x): void {} }
echo "ok\n";
--EXPECT_EXIT--
255
