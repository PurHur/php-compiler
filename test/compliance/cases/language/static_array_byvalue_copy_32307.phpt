--TEST--
Language: by-value assign of a static array does not alias storage (#32307, zend_array_dup)
--FILE--
<?php
class A
{
    public static $a = [1];
}
$b = A::$a;
$b[0] = 99;
var_dump(A::$a[0]);

class T
{
    public static array $a = [1];
}
$c = T::$a;
$c[0] = 99;
var_dump(T::$a[0]);

T::$a[0] = 99;
var_dump(T::$a[0]);
--EXPECT--
int(1)
int(1)
int(99)
