--TEST--
stdlib array_udiff* inline array_keys() + string strcmp callback (#14021)
--FILE--
<?php
declare(strict_types=1);

$inline = array_udiff(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]), 'strcmp');
var_export(1 === count($inline) && 'b' === ($inline[1] ?? null));
echo "\n";

$inline2 = array_udiff_assoc(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9]), 'strcmp');
var_export(1 === count($inline2) && 'b' === ($inline2[1] ?? null));
echo "\n";

$inline3 = array_uintersect(array_keys(['a' => 1, 'b' => 2]), array_keys(['a' => 9, 'c' => 3]), 'strcmp');
var_export(1 === count($inline3) && 'a' === ($inline3[0] ?? null));
echo "\n";
--EXPECT--
true
true
true
