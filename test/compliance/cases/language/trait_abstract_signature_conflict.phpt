--TEST--
Language: incompatible abstract trait method signatures — DECLARE-time fatal (#26381, Zend/zend_inheritance.c)
--INI--
display_errors=1
--FILE--
<?php
trait T1 { abstract public function f(): int; }
trait T2 { abstract public function f(): string; }
class C {
    use T1, T2;
    public function f(): int { return 1; }
}
echo (new C)->f(), "\n";
--EXPECTF--

Fatal error: Declaration of C::f(): int must be compatible with T2::f(): string in %s on line %d
--EXPECT_EXIT--
255
