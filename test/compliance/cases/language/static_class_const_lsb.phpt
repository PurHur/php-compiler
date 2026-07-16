--TEST--
Language: static::CONST late static binding uses called class (#19614)
--FILE--
<?php
class A {
    const X = 1;
    static function f() { return static::X; }
    static function selfX() { return self::X; }
}
class B extends A {
    const X = 2;
}
echo B::f(), "\n";
echo B::selfX(), "\n";
echo A::f(), "\n";
// Const declared after the method must also LSB (no order dependence).
class C {
    static function f() { return static::Y; }
    const Y = 10;
}
class D extends C {
    const Y = 20;
}
echo D::f(), "\n";
--EXPECT--
2
1
1
20
