--TEST--
Language: trait static property hooks must compile-error (#6901, php-src 8.4)
--FILE--
<?php
trait T {
    public static string $x {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
class C { use T; }
echo "compiled\n";
--EXPECT_EXIT--
255
