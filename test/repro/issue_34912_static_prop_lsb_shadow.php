<?php

/**
 * AOT: static::$prop must use called class when a child shadows the property (#34912).
 * Zend/VM: B; broken AOT folded static:: to self:: → A.
 */
class A
{
    public static $x = 'A';

    public static function g()
    {
        return static::$x;
    }

    public static function s()
    {
        return self::$x;
    }
}

class B extends A
{
    public static $x = 'B';
}

echo 'static:', B::g(), ' self:', B::s(), ' B::', B::$x, ' A::', A::$x, PHP_EOL;
