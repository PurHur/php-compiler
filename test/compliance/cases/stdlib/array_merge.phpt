--TEST--
stdlib array_merge() for list arrays
--FILE--
<?php
$a = array(1, 2);
$b = array(3, 4);
$m = array_merge($a, $b);
echo count($m), "\n";
echo $m[0], "\n";
echo $m[1], "\n";
echo $m[2], "\n";
echo $m[3], "\n";
--EXPECT--
4
1
2
3
4
