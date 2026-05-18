--TEST--
stdlib array_pop()
--FILE--
<?php
$a = array(1, 2, 3);
echo array_pop($a), "\n";
echo count($a), "\n";
echo array_pop($a), "\n";
echo array_pop($a), "\n";
echo array_pop($a) === null ? 'y' : 'n', "\n";
echo count($a), "\n";
--EXPECT--
3
2
2
1
y
0
