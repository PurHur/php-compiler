<?php
// #31912 — eval() from a method inherits self/static class scope (zend_eval_string).
class C
{
    public const K = 7;
    public static $s = 3;

    public function f()
    {
        echo eval('return self::class;'), "\n";
        echo eval('return static::class;'), "\n";
        echo eval('return self::K;'), "\n";
        echo eval('return self::$s;'), "\n";
    }

    public static function g()
    {
        echo eval('return self::class;'), "\n";
    }
}
class D extends C
{
}
(new D())->f();
C::g();
