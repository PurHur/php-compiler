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
