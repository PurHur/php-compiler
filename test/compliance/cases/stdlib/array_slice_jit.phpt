--TEST--
stdlib array_slice() JIT
--FILE--
<?php
$a = array(10, 20, 30, 40);
$s = array_slice($a, 1, 2);
echo count($s), "\n";
echo $s[0], "\n";
echo $s[1], "\n";
$t = array_slice($a, 2);
echo count($t), "\n";
echo $t[0], "\n";
echo $t[1], "\n";
--EXPECT--
2
20
30
2
30
40
