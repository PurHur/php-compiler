--TEST--
Language: static property hooks self-referential get/set dispatch (#9725, zend_property_hooks.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public static int $x {
        get => self::$x + 1;
        set => self::$x = $value - 1;
    }
}
C::$x = 10;
echo C::$x, "\n";
--EXPECT--
10
