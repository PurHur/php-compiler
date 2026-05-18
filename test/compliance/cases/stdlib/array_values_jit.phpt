--TEST--
stdlib array_values() JIT
--FILE--
<?php
$a = array(1, 2, 3);
$b = array_values($a);
echo count($b), "\n";
echo $b[0], "\n";
echo $b[2], "\n";
$c = array(10, 20);
$d = array_values($c);
echo count($d), "\n";
echo $d[1], "\n";
--EXPECT--
3
1
3
2
20
