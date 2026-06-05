--TEST--
Static property hooks — compound assign and inc/dec dispatch get+set hooks (#6438, zend_property_hooks.c)
--FILE--
<?php
class Counter {
    private static string $_v = '0';
    public static string $n {
        get => self::$_v;
        set => self::$_v = (string)((int)self::$_v + (int)$value);
    }
}
Counter::$n .= '1';
Counter::$n++;
echo Counter::$n, "\n";
--EXPECT--
3
