--TEST--
Language: array relational compare — Zend zend_compare_arrays parity (#5295)
--FILE--
<?php
$a = [1];
$b = [2];

var_dump($a < $b);
var_dump($a > $b);
var_dump($a <= [1]);
var_dump($a >= $b);

echo [1, 2] < [1, 3] ? "lt\n" : "not lt\n";
echo [1, 2, 3] > [1, 2] ? "gt\n" : "not gt\n";
echo ['a' => 1] <= ['a' => 1] ? "le\n" : "not le\n";
echo ['a' => 2] >= ['a' => 1] ? "ge\n" : "not ge\n";
?>
--EXPECT--
bool(true)
bool(false)
bool(true)
bool(false)
lt
gt
le
ge
