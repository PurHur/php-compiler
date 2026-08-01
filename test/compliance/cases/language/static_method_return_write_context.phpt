--TEST--
Language: static method return in write context — compile-time fatal (#26436)
--FILE--
<?php
class C {
    public static int $x = 1;
    public static function &get(): int { return self::$x; }
}
C::get() = 5;
echo "ASSIGNED\n";
--EXPECT_EXIT--
255
