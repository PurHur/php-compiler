--TEST--
AOT: intval()/floatval() on backed enum cases (#5623)
--FILE--
<?php
enum E: int
{
    case A = 5;
}

$c = E::A;
echo @intval($c), "\n";
echo @floatval($c), "\n";
--EXPECT--
5
5
