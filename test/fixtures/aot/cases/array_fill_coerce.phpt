--TEST--
AOT: array_fill() numeric-string start/count coercion
--FILE--
<?php
declare(strict_types=1);

$a = array_fill('1', '2', 'z');
echo count($a), "\n";
echo $a[1], '|', $a[2], "\n";
--EXPECT--
2
z|z
--EXPECT_EXIT--
0
