--TEST--
stdlib array_all()/array_any() array:/callback: named parameters (#10076, ext/standard/array.c)
--FILE--
<?php
$a = [1, 2, 3];
var_export(array_all(array: $a, callback: fn ($v) => $v > 0));
echo "\n";
var_export(array_any(array: $a, callback: fn ($v) => $v > 2));
echo "\n";
var_export(array_any(callback: fn ($v) => $v > 2, array: $a));
echo "\n";
var_export(array_all(array: [1, 2, 3], callback: fn ($v) => is_int($v)));
echo "\n";
--EXPECT--
true
true
true
true
