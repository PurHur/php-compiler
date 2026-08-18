--TEST--
AOT: ??= on uninitialized static property stores; readback matches Zend (#32035)
--FILE--
<?php
class C32035Aot
{
    public static $x;
}

C32035Aot::$x ??= 7;
var_dump(C32035Aot::$x);
C32035Aot::$x ??= 9;
var_dump(C32035Aot::$x);

class T32035Aot
{
    public static int $n;
}

T32035Aot::$n ??= 7;
var_dump(T32035Aot::$n);
T32035Aot::$n ??= 9;
var_dump(T32035Aot::$n);
?>
--EXPECT--
int(7)
int(7)
int(7)
int(7)
--EXPECT_EXIT--
0
