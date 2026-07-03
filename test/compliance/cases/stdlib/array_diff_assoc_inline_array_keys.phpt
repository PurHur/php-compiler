--TEST--
stdlib array_diff_assoc() inline array_keys() haystack — nested builtin arg wiring (#15569, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);

$expected = [1 => 'b'];

$inline = array_diff_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]));
var_export($inline === $expected);
echo "\n";

$left = array_keys(['a' => 1, 'b' => 2]);
$right = array_keys(['a' => 9]);
$variable = array_diff_assoc($left, $right);
var_export($variable === $expected);
echo "\n";
--EXPECT--
true
true
