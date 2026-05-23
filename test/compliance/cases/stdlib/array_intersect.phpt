--TEST--
stdlib array_intersect() for list arrays (loose compare)
--FILE--
<?php
$a = array(1, 2, 3, 4);
$b = array(2, 4, 5);
$c = array(2, 4, 6);
$d = array_intersect($a, $b, $c);
echo count($d), "\n";
echo $d[1], "\n";
echo $d[3], "\n";
$x = array('a', 'b', 'c');
$y = array('b', 'c');
$z = array_intersect($x, $y);
echo count($z), "\n";
echo $z[1], "\n";
echo $z[2], "\n";
--EXPECT--
2
2
4
2
b
c
