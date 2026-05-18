--TEST--
Integration: array_keys, array_merge, array_slice
--FILE--
<?php
$a = array(5, 6);
$b = array(7);
$k = array_keys($a);
echo count($k), "\n";
$m = array_merge($a, $b);
echo count($m), "\n";
echo $m[2], "\n";
$s = array_slice($m, 1, 2);
echo count($s), "\n";
echo $s[0], "\n";
echo $s[1], "\n";
--EXPECT--
2
3
7
2
6
7
