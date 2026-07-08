--TEST--
stdlib array_all_key()/array_any_key() key-aware predicates (#15238, #15166, ext/standard/array.c)
--FILE--
<?php
$a = ['a' => 1, 'b' => 2, 'c' => 3];
var_export(array_all_key($a, fn ($k, $v) => is_string($k) && $v > 0));
echo "\n";
var_export(array_any_key($a, fn ($k, $v) => $k === 'b'));
echo "\n";
var_export(array_any_key([10, 20, 30], fn ($k, $v) => $k === 1 && $v === 20));
echo "\n";
var_export(array_all_key([], fn () => false));
echo "\n";
var_export(array_any_key([], fn () => true));
echo "\n";
--EXPECT--
true
true
true
true
false
