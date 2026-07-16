--TEST--
child self::PRIVATE_CONST is Undefined (not parent private leak) (#19615, zend_constants.c)
--FILE--
<?php
class A {
    private const X = 1;
    public static function f() { return self::X; }
}
class B extends A {
    public static function g() { return self::X; }
}
echo A::f(), "\n";
try {
    echo B::g(), "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo B::X, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo A::X, "\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
1
Undefined constant B::X
Undefined constant B::X
Cannot access private constant A::X
