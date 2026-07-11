--TEST--
stdlib array_udiff inline array_keys() + strcmp(...) FCC callback (#15475)
--FILE--
<?php
declare(strict_types=1);

$expected = [1 => 'b'];
$inline = array_udiff(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]), strcmp(...));
var_export($inline === $expected);
echo "\n";

$left = array_keys(['a' => 1, 'b' => 2]);
$right = array_keys(['a' => 9]);
$variable = array_udiff($left, $right, strcmp(...));
var_export($variable === $expected);
echo "\n";
--EXPECT--
true
true
