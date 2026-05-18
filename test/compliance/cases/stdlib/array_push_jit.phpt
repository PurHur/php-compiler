--TEST--
stdlib array_push() JIT
--FILE--
<?php
$a = array(1);
echo count($a), "\n";
echo array_push($a, 2, 3), "\n";
echo count($a), "\n";
echo in_array(3, $a) ? 'y' : 'n', "\n";
--EXPECT--
1
3
3
y
