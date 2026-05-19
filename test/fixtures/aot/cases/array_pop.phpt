--TEST--
AOT: array_pop() on packed list arrays
--FILE--
<?php
$a = array(1, 2, 3);
echo array_pop($a), "\n";
echo count($a), "\n";
--EXPECT--
3
2
