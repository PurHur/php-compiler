--TEST--
AOT: array_shift() on packed list arrays
--FILE--
<?php
$a = array(10, 20, 30);
echo array_shift($a), "\n";
echo count($a), "\n";
echo array_shift($a), "\n";
echo count($a), "\n";
--EXPECT--
10
2
20
1
