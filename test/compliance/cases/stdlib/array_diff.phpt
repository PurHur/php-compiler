--TEST--
stdlib array_diff() for list arrays (loose compare)
--FILE--
<?php
$a = array(1, 2, 3, 4);
$b = array(2, 4);
$d = array_diff($a, $b);
echo count($d), "\n";
echo $d[0], "\n";
echo $d[2], "\n";
$x = array('a', 'b', 'c');
$y = array('b');
$z = array_diff($x, $y);
echo count($z), "\n";
echo $z[0], "\n";
echo $z[2], "\n";
--EXPECT--
2
1
3
2
a
c
