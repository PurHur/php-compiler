--TEST--
AOT: array_unique() on string list
--FILE--
<?php
$b = array('a', 'b', 'a');
$ub = array_unique($b);
echo count($ub), "\n";
--EXPECT--
2
