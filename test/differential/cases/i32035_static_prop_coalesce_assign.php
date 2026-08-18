<?php
// @differential-repeat: 10   AOT static ??= store missed the module global (#32035)
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
