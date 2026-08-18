<?php
/**
 * Issue #32083 — instance-method FCC must not poison later static::class / : static.
 *
 * php-src: Zend/zend_compile.c ZEND_AST_CALLABLE_CONVERT; Zend/zend_execute.c LSB.
 */
error_reporting(E_ALL);
class FM {
    public function m($x) { return $x * 2; }
}
$c = (new FM())->m(...);
echo 'fcc=', $c(4), "\n";

class Base {
    public static function who() { return static::class; }
}
class Child extends Base {}
echo 'lsb=', Base::who(), ' ', Child::who(), "\n";

class A3 {
    public function f(): object { return new stdClass(); }
}
class B3 extends A3 {
    public function f(): static { return $this; }
}
echo 'ret=', get_class((new B3())->f()), "\n";
