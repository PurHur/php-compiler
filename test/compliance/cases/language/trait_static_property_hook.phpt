--TEST--
Language: trait static property hooks merge into using class (#6624, zend_property_hooks.c + zend_traits.c)
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
C::$x = 'hi';
echo C::$x, "\n";
var_export(isset(C::$x));
echo "\n";
--EXPECT--
hi
true
