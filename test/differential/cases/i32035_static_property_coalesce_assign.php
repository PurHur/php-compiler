<?php
// @differential-repeat: 10   AOT static ??= store was a no-op (readback NULL, #32035)
class C32035
{
    public static $x;
}

C32035::$x ??= 7;
echo C32035::$x, "\n";
C32035::$x ??= 9;
echo C32035::$x, "\n";
