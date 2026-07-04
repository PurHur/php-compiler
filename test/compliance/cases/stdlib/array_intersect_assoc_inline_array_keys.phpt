--TEST--
stdlib array_intersect_assoc() inline array_keys() haystack — nested builtin arg wiring (#15570, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$expected = [0 => 'a'];

$inline = array_intersect_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9, 'c' => 3]));
var_export($inline === $expected);
echo "\n";

$left = array_keys(['a' => 1, 'b' => 2]);
$right = array_keys(['a' => 9, 'c' => 3]);
$variable = array_intersect_assoc($left, $right);
var_export($variable === $expected);
echo "\n";
--EXPECT--
true
true
