--TEST--
Language: child may add by-ref return when parent returns by-value (zend_inheritance.c, #26530)
--FILE--
<?php
class A { public function f(): int { return 1; } }
class B extends A { public function &f(): int { $x = 1; return $x; } }
echo "accepted\n";
--EXPECT--
accepted
