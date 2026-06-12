--TEST--
AOT call_user_func() string + closure callbacks (issue #3132)
--FILE--
<?php
function add(int $a, int $b): int { return $a + $b; }
$fn = 'add';
echo call_user_func($fn, 2, 3), "\n";
$c = function (int $x): int { return $x * 2; };
echo call_user_func($c, 7), "\n";
?>
--EXPECT--
5
14
