--TEST--
AOT: array_reverse() on packed list arrays
--FILE--
<?php
$a = array(1, 2, 3);
$b = array_reverse($a);
echo count($b), "\n";
echo $b[0], "\n";
echo $b[2], "\n";
--EXPECT--
3
3
1
