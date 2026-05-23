--TEST--
AOT: array_keys() for list arrays (stdlib parity with compliance PHPT)
--FILE--
<?php
$a = array(10, 20, 30);
$k = array_keys($a);
echo count($k), "\n";
echo $k[0], "\n";
echo $k[1], "\n";
echo $k[2], "\n";
--EXPECT--
3
0
1
2
