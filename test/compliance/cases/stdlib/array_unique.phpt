--TEST--
stdlib array_unique()
--FILE--
<?php
$a = array(1, 2, 1, 3, 2);
echo count(array_unique($a)), "\n";
$b = array('a', 'b', 'a');
echo count(array_unique($b)), "\n";
$c = array(1, 2, 1);
echo count(array_unique($c)), "\n";
--EXPECT--
3
2
2
