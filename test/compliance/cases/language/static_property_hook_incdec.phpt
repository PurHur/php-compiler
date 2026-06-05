--TEST--
Static property hooks — post/pre inc/dec dispatch get+set hooks (#6319, zend_property_hooks.c)
--FILE--
<?php
class Counter {
    private static int $n = 0;
    public static int $count {
        get => self::$n;
        set (int $v) { self::$n = $v; }
    }
}
Counter::$count = 1;
echo Counter::$count++, "\n";
var_export(Counter::$count);
echo "\n";
++Counter::$count;
var_export(Counter::$count);
--EXPECT--
1
2
3
