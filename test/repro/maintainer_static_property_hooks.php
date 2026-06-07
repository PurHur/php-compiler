<?php
// Issue #4751 / #6624 — static property hooks on direct class (VM path).
class C {
    public static string $x {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
C::$x = 'ok';
echo C::$x, "\n";
