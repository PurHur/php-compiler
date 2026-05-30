--TEST--
inheritance variance: contravariant parameter widening allowed (issue #3323)
--FILE--
<?php
interface I { public function f(B $x): void; }
class A {}
class B extends A {}
class C implements I { public function f(A $x): void {} }
echo "ok\n";
--EXPECT--
ok
