--TEST--
stdlib array_udiff* inline array_keys() + string strcmp callback (#14021)
--FILE--
<?php
declare(strict_types=1);

$expected = [1 => 'b'];
$inline = array_udiff(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]), 'strcmp');
var_export($inline === $expected);
echo "\n";

$inline2 = array_udiff_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]), 'strcmp');
var_export($inline2 === $expected);
echo "\n";

$expectedUi = [0 => 'a'];
$inline3 = array_uintersect(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9, 'c' => 3]), 'strcmp');
var_export($inline3 === $expectedUi);
echo "\n";
--EXPECT--
true
true
true
