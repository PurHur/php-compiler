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
