--TEST--
AOT: array_unique() strict identity on packed lists
--FILE--
<?php
$a = array(1, 2, 1, 3, 2);
echo count(array_unique($a)), "\n";
$b = array('a', 'b', 'a');
echo count(array_unique($b)), "\n";
--EXPECT--
3
2
