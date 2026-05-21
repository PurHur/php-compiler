--TEST--
AOT: count() and sizeof() on packed list arrays
--FILE--
<?php
$a = array(1, 2, 3);
echo count($a), "\n";
echo count(array()), "\n";
$b = array(10, 20, 30, 40);
echo count($b), "\n";
echo $b[2], "\n";
echo sizeof($a), "\n";
--EXPECT--
3
0
4
30
3
