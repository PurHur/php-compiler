--TEST--
stdlib count() JIT for packed and associative arrays
--FILE--
<?php
$a = array(1, 2, 3);
echo count($a), "\n";
echo count(array()), "\n";
$b = array(10, 20, 30, 40);
echo count($b), "\n";
echo $b[2], "\n";
echo sizeof($a), "\n";
$assoc = array('x' => 10, 'y' => 20);
echo count($assoc), "\n";
--EXPECT--
3
0
4
30
3
2
