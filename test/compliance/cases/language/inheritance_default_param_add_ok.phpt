--TEST--
Language: child adding default where parent has none allowed (zend_inheritance.c, #26520)
--FILE--
<?php
class A { public function f(int $x) {} }
class B extends A { public function f(int $x = 1) { echo "ok\n"; } }
(new B)->f();
--EXPECT--
ok
