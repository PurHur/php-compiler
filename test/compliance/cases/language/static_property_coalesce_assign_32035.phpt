--TEST--
Language: ??= on uninitialized static property stores and readback matches Zend (#32035)
--FILE--
<?php
error_reporting(E_ALL);

class C32035
{
    public static $x;
}

C32035::$x ??= 7;
var_dump(C32035::$x);
C32035::$x ??= 9;
var_dump(C32035::$x);

class T32035
{
    public static int $n;
}

T32035::$n ??= 7;
var_dump(T32035::$n);
T32035::$n ??= 9;
var_dump(T32035::$n);

class U32035
{
    public static $y;
}

var_dump(U32035::$y ??= 3);
var_dump(U32035::$y);
?>
--EXPECT--
int(7)
int(7)
int(7)
int(7)
int(3)
int(3)
