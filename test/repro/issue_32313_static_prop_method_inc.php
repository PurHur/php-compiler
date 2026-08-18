<?php
/**
 * #32313 — untyped static property ++ must FETCH_STATIC_PROP_RW and store back.
 * Zend zend_operators.c increment_function / ZEND_POST_INC.
 */
class C
{
    public static $x = 1;

    public static function bump(): int
    {
        return self::$x++;
    }
}

class T
{
    public static int $n = 1;

    public function bump(): int
    {
        return self::$n++;
    }
}

class A
{
    public static $z = 1;

    public static function add(): void
    {
        self::$z += 1;
    }
}

echo C::bump(), C::bump(), C::$x, "\n";
$t = new T();
echo $t->bump(), $t->bump(), T::$n, "\n";
A::add();
A::add();
echo A::$z, "\n";
