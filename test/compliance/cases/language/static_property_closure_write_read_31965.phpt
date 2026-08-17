--TEST--
Language: static property write inside closure persists on read-back (#31965, zend_closures.c)
--FILE--
<?php
class C31965
{
    public static int $x = 0;

    public static function run(): void
    {
        $f = function (): void {
            self::$x = 12;
        };
        $f();
        echo self::$x, "\n";
    }
}

C31965::run();
--EXPECT--
12
