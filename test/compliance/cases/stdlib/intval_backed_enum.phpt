--TEST--
stdlib intval()/floatval() on backed enum cases — E_WARNING + backing scalar (#5623)
--FILE--
<?php
enum E: int
{
    case A = 1;
}

enum S: string
{
    case X = '42';
}

$c = E::A;
echo 'intval: ', @intval($c), "\n";
@intval($c);
$err = error_get_last();
echo 'warning: ', $err['message'] ?? '', "\n";
echo 'floatval: ', @floatval($c), "\n";
$s = S::X;
echo 'intval S: ', @intval($s), "\n";
--EXPECT--
intval: 1
warning: Object of class E could not be converted to int
floatval: 1
intval S: 42
