--TEST--
Language: matching by-ref return override accepted (zend_inheritance.c, #26530)
--FILE--
<?php
class A { public function &f(): int { $x = 1; return $x; } }
class B extends A { public function &f(): int { $x = 2; return $x; } }
echo "accepted\n";
--EXPECT--
accepted
