--TEST--
AOT: typed static property write from closure marks init (#31965)
--FILE--
<?php
class C31965AotTyped
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

C31965AotTyped::run();
?>
--EXPECT--
12
