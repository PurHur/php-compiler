--TEST--
AOT: untyped static property ++ in a method stores back (#32313, ZEND_POST_INC)
--FILE--
<?php
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
--EXPECT--
123
123
3
--EXPECT_EXIT--
0
