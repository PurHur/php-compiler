--TEST--
stdlib array_unshift() JIT
--FILE--
<?php
$a = array(20, 30);
echo array_unshift($a, 10), "\n";
echo count($a), "\n";
echo in_array(10, $a) ? 'y' : 'n', "\n";
--EXPECT--
3
3
y
