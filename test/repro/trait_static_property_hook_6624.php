<?php
trait T {
    public static string $x {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
class C { use T; }
C::$x = 'hi';
echo C::$x;
