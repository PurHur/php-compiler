--TEST--
AOT: count() on packed and associative arrays
--FILE--
<?php
$a = array(1, 2, 3);
echo count($a), "\n";
echo count(array()), "\n";
$assoc = array('x' => 10, 'y' => 20);
echo count($assoc), "\n";
--EXPECT--
3
0
2
