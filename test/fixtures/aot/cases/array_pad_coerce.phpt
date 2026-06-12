--TEST--
AOT: array_pad() numeric-string length coercion (#4269)
--FILE--
<?php
declare(strict_types=1);

$a = array_pad([1, 2], '5', 'z');
echo count($a), "\n";
echo $a[0], '|', $a[4], "\n";
--EXPECT--
5
1|z
--EXPECT_EXIT--
0
