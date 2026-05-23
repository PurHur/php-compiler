--TEST--
stdlib array_unshift()
--FILE--
<?php
$a = array(2, 3);
echo array_unshift($a, 0, 1), "\n";
echo count($a), "\n";
echo $a[0], "\n";
echo $a[1], "\n";
echo $a[2], "\n";
echo $a[3], "\n";
$b = array();
echo array_unshift($b, 9), "\n";
echo $b[0], "\n";
--EXPECT--
4
4
0
1
2
3
1
9
