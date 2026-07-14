--TEST--
parent:: in trait methods resolves to composing class parent (#18878, Zend/zend_traits.c)
--FILE--
<?php
trait T {
    public function g(): string {
        return parent::f();
    }
    public static function gs(): string {
        return parent::fs();
    }
}
class P {
    public function f(): string {
        return 'p';
    }
    public static function fs(): string {
        return 'ps';
    }
}
class C extends P {
    use T;
}
echo (new C)->g(), "\n";
echo C::gs(), "\n";
--EXPECT--
p
ps
