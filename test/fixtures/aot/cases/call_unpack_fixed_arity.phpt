--TEST--
AOT: call-time argument unpack into fixed-arity user function (#24144)
--FILE--
<?php
function f($a, $b) { echo "$a-$b\n"; }
f(...[1, 2]);
$a = [3, 4];
f(...$a);
f(5, ...[6]);
function g($a, $b = 9) { echo "$a-$b\n"; }
g(...[7]);
--EXPECT--
1-2
3-4
5-6
7-9
