--TEST--
stdlib array_find()/array_all()/array_any() optional $strict third parameter JIT (#6949, ext/standard/array.c)
--JIT--
--FILE--
<?php
$haystack = [1, '1', 2];
var_export(array_find($haystack, fn ($v) => $v == 1 ? 1 : 0, true));
echo "\n";
var_export(array_find_key($haystack, fn ($v) => $v == 1 ? 1 : 0, true));
echo "\n";
$h = ['a' => 1, 'b' => '1'];
var_export(array_all($h, fn ($v, $k) => $v == 1 ? 1 : 0, true));
echo "\n";
var_export(array_any($h, fn ($v, $k) => $v == 1 ? 1 : 0, true));
echo "\n";
--EXPECT--
NULL
NULL
false
false
