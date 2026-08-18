--TEST--
Language: ??= on uninitialized static property stores like Zend (#32035)
--FILE--
<?php
class C32035
{
    public static $x;
}

C32035::$x ??= 7;
var_dump(C32035::$x);
C32035::$x ??= 9;
var_dump(C32035::$x);

class S32035
{
    public static int $y;
}

S32035::$y ??= 4;
var_dump(S32035::$y);
?>
--EXPECT--
int(7)
int(7)
int(4)
