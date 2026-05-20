--TEST--
AOT: array_fill() with string values (packed list)
--FILE--
<?php
$a = array_fill(0, 3, 'x');
echo count($a), "\n";
echo $a[0], $a[1], $a[2], "\n";
$b = array_fill(2, 2, 7);
echo $b[2], '|', $b[3], "\n";
--EXPECT--
3
xxx
7|7
--EXPECT_EXIT--
0
