--TEST--
stdlib array_intersect() JIT
--FILE--
<?php
$a = array('a', 'b', 'c');
$b = array('b', 'c', 'd');
$i = array_intersect($a, $b);
echo count($i), "\n";
echo $i[1], "\n";
echo $i[2], "\n";
--EXPECT--
2
b
c
