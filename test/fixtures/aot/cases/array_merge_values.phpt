--TEST--
AOT: array_values() after array_merge() preserves string elements
--FILE--
<?php
$b = array_merge(['x', 'y', 'z'], ['w']);
$d = array_values($b);
echo count($d), "\n";
echo $d[0], "\n";
echo $d[3], "\n";
--EXPECT--
4
x
w
