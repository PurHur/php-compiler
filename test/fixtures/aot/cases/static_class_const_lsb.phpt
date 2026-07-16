--TEST--
AOT: static::CONST late static binding uses called class (#19614)
--FILE--
<?php
class A {
    const X = 1;
    static function f() { return static::X; }
}
class B extends A {
    const X = 2;
}
echo B::f();
--EXPECT--
2
