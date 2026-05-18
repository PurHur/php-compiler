--TEST--
Integration: array_push, array_pop, array_shift, array_values
--FILE--
<?php
$list = array('a');
array_push($list, 'b', 'c');
echo count($list), "\n";
echo array_pop($list), "\n";
echo count($list), "\n";
echo array_shift($list), "\n";
echo count($list), "\n";
$vals = array_values(array('x', 'y'));
echo count($vals), "\n";
echo sizeof($vals), "\n";
--EXPECT--
3
c
2
a
1
2
2
