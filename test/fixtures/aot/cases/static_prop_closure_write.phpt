--TEST--
AOT: static property write/read through closure is stable (#31965)
--FILE--
<?php
class C31965Aot
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

C31965Aot::run();
?>
--EXPECT--
12
