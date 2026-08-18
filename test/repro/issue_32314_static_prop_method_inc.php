<?php
/**
 * #32314 / #31968 group 3 — untyped static ++/-- in a method must persist.
 * php-src: Zend/zend_operators.c increment_function; Zend/zend_vm_def.h ZEND_POST_INC.
 */
class C
{
    public static $x = 1;
    public static function inc()
    {
        self::$x++;
    }
    public static function pre()
    {
        ++self::$x;
    }
}
class T
{
    public static int $n = 1;
    public static function inc()
    {
        self::$n++;
    }
}
C::inc();
C::inc();
var_dump(C::$x);
C::pre();
var_dump(C::$x);
T::inc();
T::inc();
var_dump(T::$n);
