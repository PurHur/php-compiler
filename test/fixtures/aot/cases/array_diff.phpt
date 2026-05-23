--TEST--
AOT array_diff() on scalar lists
--FILE--
<?php
$a = array(1, 2, 3);
$b = array(2, 3);
$d = array_diff($a, $b);
echo count($d), "\n";
echo $d[0], "\n";
--EXPECT--
1
1
