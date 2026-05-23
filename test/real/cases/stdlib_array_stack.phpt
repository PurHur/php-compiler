--TEST--
Integration: array_push, array_pop, array_shift, array_unshift, array_values
--FILE--
<?php
$list = array('a');
array_push($list, 'b', 'c');
echo count($list), "\n";
echo array_pop($list), "\n";
echo count($list), "\n";
echo array_shift($list), "\n";
echo count($list), "\n";
echo array_unshift($list, 'y', 'z'), "\n";
echo $list[0], "\n";
echo $list[1], "\n";
$vals = array_values(array('x', 'y'));
echo count($vals), "\n";
echo sizeof($vals), "\n";
--EXPECT--
3
c
2
a
1
3
y
z
2
2
