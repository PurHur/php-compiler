--TEST--
Language: static property hooks compile and run (#6931, zend_property_hooks.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public static string $x {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
C::$x = 'b';
echo C::$x, "\n";
--EXPECT--
b
