--TEST--
AOT array_intersect() on scalar lists
--FILE--
<?php
$a = array(1, 2, 3);
$b = array(2, 3);
$d = array_intersect($a, $b);
echo count($d), "\n";
echo $d[1], "\n";
echo $d[2], "\n";
--EXPECT--
2
2
3
