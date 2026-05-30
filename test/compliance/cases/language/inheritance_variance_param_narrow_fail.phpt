--TEST--
inheritance variance: narrow parameter type rejected at compile time (issue #3323)
--FILE--
<?php
interface I { public function f(A $x): void; }
class A {}
class B extends A {}
class C implements I { public function f(B $x): void {} }
echo "ok\n";
--EXPECT_EXIT--
255
