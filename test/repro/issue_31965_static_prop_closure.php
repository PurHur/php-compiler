<?php
// Issue #31965 — static property write/read through closure (AOT uninitialised memory)

class C31965
{
    public static $x;

    public static function run(): void
    {
        $f = static function (): void {
            self::$x = 12;
        };
        $f();
        echo self::$x, "\n";
    }
}

C31965::run();
