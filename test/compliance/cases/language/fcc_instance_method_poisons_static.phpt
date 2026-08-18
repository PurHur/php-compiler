--TEST--
Language: instance-method FCC must not poison later static::class / : static (#32083, zend_compile.c)
--FILE--
<?php
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
--EXPECT--
fcc=8
lsb=Base Child
ret=B3
