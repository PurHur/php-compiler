--TEST--
Language: static property hook get reads self-backed uninitialized slot via ?? (#9683, zend_property_hooks.c)
--FILE--
<?php
declare(strict_types=1);

class C {
    public static int $n {
        get => self::$n ?? 0;
        set (int $v) { self::$n = $v; }
    }
}
echo C::$n, "\n";
C::$n = 5;
echo C::$n, "\n";
--EXPECT--
0
5
