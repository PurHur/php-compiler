--TEST--
stdlib array_unshift() JIT
--FILE--
<?php
$a = array(20, 30);
echo array_unshift($a, 10), "\n";
echo count($a), "\n";
echo $a[0], "\n";
echo array_unshift($a, 0, 5), "\n";
echo $a[0], "\n";
echo $a[1], "\n";
echo count($a), "\n";
--EXPECT--
3
3
10
5
0
5
5
