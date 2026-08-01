--TEST--
Language: by-ref return dropped on override rejected (zend_inheritance.c, #26530)
--FILE--
<?php
class A { public function &f(): int { $x = 1; return $x; } }
class B extends A { public function f(): int { return 1; } }
echo "accepted\n";
--EXPECTF--
Fatal error: Declaration of B::f(): int must be compatible with & A::f(): int in %s on line %d
--EXPECT_EXIT--
255
