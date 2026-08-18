--TEST--
untyped static property ++/-- inside a method persists (#32314, zend_operators.c increment_function)
--FILE--
<?php
class C {
    public static $x = 1;
    public static function inc() { self::$x++; }
    public static function pre() { ++self::$x; }
}
C::inc();
C::inc();
var_dump(C::$x);
C::pre();
var_dump(C::$x);
--EXPECT--
int(3)
int(4)
