--TEST--
AOT: array_merge_recursive() list append (#3297)
--FILE--
<?php
$c = array(1, 2);
$d = array(3, 4);
$e = array_merge_recursive($c, $d);
echo count($e), "\n";
echo $e[0], "\n";
echo $e[1], "\n";
echo $e[2], "\n";
echo $e[3], "\n";
--EXPECT--
4
1
2
3
4
