--TEST--
AOT: ??= on uninitialized static property stores and persists (#32035)
--FILE--
<?php
class C32035
{
    public static $x;
}

C32035::$x ??= 7;
echo C32035::$x, "\n";
C32035::$x ??= 9;
echo C32035::$x, "\n";

class S32035
{
    public static int $y;
}

S32035::$y ??= 4;
echo S32035::$y, "\n";
?>
--EXPECT--
7
7
4
