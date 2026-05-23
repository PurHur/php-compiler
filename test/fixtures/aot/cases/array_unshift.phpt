--TEST--
AOT: array_unshift() on packed list arrays
--FILE--
<?php
$a = array(2, 3);
echo array_unshift($a, 0, 1), "\n";
echo count($a), "\n";
echo $a[0], "\n";
echo $a[3], "\n";
--EXPECT--
4
4
0
3
