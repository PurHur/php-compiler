--TEST--
AOT: array_values() on packed list arrays
--FILE--
<?php
$a = array(1, 2, 3);
$b = array_values($a);
echo count($b), "\n";
echo $b[0], "\n";
echo $b[2], "\n";
$n = array(10, 20);
echo count(array_values($n)), "\n";
--EXPECT--
3
1
3
2
