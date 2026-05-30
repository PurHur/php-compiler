--TEST--
Dynamic static property name via Class::{$name} (issue #3788)
--FILE--
<?php
class C {
    public static int $x = 42;
    public const Y = 99;
}
$n = 'x';
echo C::{$n}, "\n";
$n = 'Y';
echo C::{$n}, "\n";
$p = 'x';
C::${$p} = 7;
echo C::{$p}, "\n";
--EXPECT--
42
99
7
