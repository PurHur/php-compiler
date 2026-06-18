--TEST--
stdlib array_unique() SORT_STRING flag
--FILE--
<?php
$a = array(1, '1', 2);
$u = array_unique($a, SORT_STRING);
echo count($u), "\n";
$v = array_values($u);
echo $v[0], "\n";
echo $v[1], "\n";
$b = array(1, '1', 2);
echo count(array_unique($b, 2)), "\n";
$c = array(1, '1', 2);
echo count(array_unique($c)), "\n";
--EXPECT--
2
1
2
2
2
