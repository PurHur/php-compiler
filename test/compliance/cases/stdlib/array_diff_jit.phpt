--TEST--
stdlib array_diff() JIT for list arrays
--FILE--
<?php
$a = array(1, 2, 3);
$b = array(2);
$d = array_diff($a, $b);
echo count($d), "\n";
echo $d[0], "\n";
echo $d[2], "\n";
--EXPECT--
2
1
3
