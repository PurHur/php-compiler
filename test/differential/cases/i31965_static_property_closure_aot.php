<?php
// @differential-repeat: 10   AOT static property via closure was unstable garbage (#31965)
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
