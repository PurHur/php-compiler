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
var_dump(array_first([]));
var_dump(array_last([]));
$allUnset = [0 => 1];
unset($allUnset[0]);
var_dump(array_first($allUnset));
var_dump(array_last($allUnset));
--EXPECT--
1
2
10
30
NULL
NULL
NULL
NULL
