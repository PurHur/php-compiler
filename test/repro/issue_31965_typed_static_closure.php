<?php
// Issue #31965 — typed static property write from closure (AOT init flag)

class C31965Typed
{
    public static int $x;

    public static function run(): void
    {
        $f = function (): void {
            self::$x = 12;
        };
        $f();
        echo self::$x, "\n";
    }
}

C31965Typed::run();
