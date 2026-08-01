--TEST--
Language: incompatible abstract trait method param types — DECLARE-time fatal (#26381, Zend/zend_inheritance.c)
--INI--
display_errors=1
--FILE--
<?php
trait T1 { abstract public function f(int $x); }
trait T2 { abstract public function f(string $x); }
class C {
    use T1, T2;
    public function f(int $x) { echo "ok\n"; }
}
(new C)->f(1);
--EXPECTF--

Fatal error: Declaration of C::f(int $x) must be compatible with T2::f(string $x) in %s on line %d
--EXPECT_EXIT--
255
