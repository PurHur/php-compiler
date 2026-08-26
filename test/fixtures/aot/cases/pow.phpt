--TEST--
AOT: pow() for integers and floats
--FILE--
<?php
echo pow(2, 3), "\n";
echo pow(10, 2), "\n";
echo pow(9, 0.5), "\n";
echo pow(2, 3.5), "\n";
// #35123 — float base + exact int exp must not NestedJIT to 1.0
var_dump(pow(2.5, 2));
var_dump(2.5 ** 2);
--EXPECT--
8
100
3
11.313708498985
float(6.25)
float(6.25)
--EXPECT_EXIT--
0
