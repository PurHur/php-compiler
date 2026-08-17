<?php
/**
 * Repro #31965 — static property write from closure must persist on read-back.
 * php-src: Zend/zend_closures.c (bound scope) + zend_object_handlers.c (static props).
 */
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
