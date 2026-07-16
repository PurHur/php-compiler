--TEST--
trait method overrides inherited parent method; parent:: binds composing parent (#19630, Zend/zend_traits.c)
--FILE--
<?php
class Base {
    public function f() {
        return 'base';
    }
    public static function fs() {
        return 'bases';
    }
}
trait T {
    public function f() {
        return parent::f() . '+T';
    }
    public static function fs() {
        return parent::fs() . '+T';
    }
}
class A extends Base {
    use T;
}
echo (new A)->f(), "\n";
echo A::fs(), "\n";
--EXPECT--
base+T
bases+T
