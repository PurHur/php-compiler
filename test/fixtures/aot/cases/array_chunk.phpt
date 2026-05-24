--TEST--
AOT: array_chunk() for list arrays (stdlib parity with compliance PHPT)
--FILE--
<?php
$a = array(10, 20, 30, 40, 50);
$c = array_chunk($a, 2);
echo count($c), "\n";
echo count($c[0]), "\n";
echo $c[0][0], "\n";
echo $c[0][1], "\n";
echo count($c[1]), "\n";
echo $c[1][0], "\n";
echo $c[1][1], "\n";
echo count($c[2]), "\n";
echo $c[2][0], "\n";
--EXPECT--
3
2
10
20
2
30
40
1
50
