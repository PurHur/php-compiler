--TEST--
stdlib array_intersect() for list arrays (loose compare)
--FILE--
<?php
$a = array(1, 2, 3, 4);
$b = array(2, 4, 6);
$i = array_intersect($a, $b);
echo count($i), "\n";
echo $i[1], "\n";
echo $i[3], "\n";
--EXPECT--
2
2
4
