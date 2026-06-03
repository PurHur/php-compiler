<?php
class Counter {
    public static int $n {
        get => self::$n;
        set => self::$n = $value * 2;
    }
}
Counter::$n = 3;
echo Counter::$n, "\n";
