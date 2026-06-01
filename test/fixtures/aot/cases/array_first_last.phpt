--TEST--
AOT: array_first() / array_last()
--FILE--
<?php
$a = ['x' => 1, 'y' => 2];
echo array_first($a), "\n";
echo array_last($a), "\n";
$list = [10, 20, 30];
echo array_first($list), "\n";
echo array_last($list), "\n";
--EXPECT--
1
2
10
30
