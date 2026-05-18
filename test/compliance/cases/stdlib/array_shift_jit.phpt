--TEST--
stdlib array_shift() JIT
--FILE--
<?php
$a = array(10, 20, 30);
echo array_shift($a), "\n";
echo count($a), "\n";
echo array_shift($a), "\n";
echo array_shift($a), "\n";
echo array_shift($a) === null ? 'y' : 'n', "\n";
echo count($a), "\n";
--EXPECT--
10
2
20
30
y
0
