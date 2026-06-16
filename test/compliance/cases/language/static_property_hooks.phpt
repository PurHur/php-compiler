--TEST--
Language: static property hooks compile and run (#6931, zend_property_hooks.c)
--FILE--
<?php
declare(strict_types=1);

class Counter {
    private static int $n = 0;
    public static int $count {
        get => self::$n;
        set => self::$n = $value;
    }
}
Counter::$count = 3;
echo Counter::$count, "\n";
Counter::$count++;
echo Counter::$count, "\n";

class Ro {
    public static string $x { get => 'ro'; set => throw new Exception('no'); }
}
try {
    Ro::$x = 'y';
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
3
4
Exception
