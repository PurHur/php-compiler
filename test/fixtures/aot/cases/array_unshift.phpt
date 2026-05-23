--TEST--
AOT: array_unshift() on packed list arrays
--FILE--
<?php
$a = array(20, 30);
echo array_unshift($a, 10), "\n";
echo count($a), "\n";
echo $a[0], "\n";
echo array_unshift($a, 0), "\n";
echo count($a), "\n";
--EXPECT--
3
3
10
4
4
