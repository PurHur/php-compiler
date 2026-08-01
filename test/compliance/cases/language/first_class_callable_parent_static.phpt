--TEST--
Language: parent::staticMethod(...) first-class callable from static context (#26252, zend_compile.c)
--FILE--
<?php
class A {
    public static function m(): string {
        return 'A';
    }
}
class B extends A {
    public static function t(): string {
        $f = parent::m(...);
        return $f();
    }
    public function fromInstance(): string {
        $f = parent::m(...);
        return $f();
    }
}
echo B::t(), "\n";
echo (new B())->fromInstance(), "\n";
--EXPECT--
A
A
