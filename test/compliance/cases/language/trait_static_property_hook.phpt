--TEST--
Language: trait static property hooks compile and run (#6931, #6624)
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
C::$x = 'ok';
echo C::$x, "\n";
--EXPECT--
ok
