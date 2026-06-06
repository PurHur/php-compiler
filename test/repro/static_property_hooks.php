<?php
// Issue #6931 — PHP 8.4 static property hooks (zend_property_hooks.c).
class C {
    public static string $x {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
C::$x = 'b';
echo C::$x, "\n";
