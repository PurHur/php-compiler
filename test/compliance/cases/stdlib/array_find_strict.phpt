--TEST--
stdlib array_find()/array_find_key()/array_all()/array_any() optional $strict third parameter (#6949, ext/standard/array.c)
--FILE--
<?php
$haystack = [1, '1', 2];
var_export(array_find($haystack, fn ($v) => $v == 1));
echo "\n";
var_export(array_find($haystack, fn ($v) => $v == 1 ? 1 : 0, true));
echo "\n";
var_export(array_find_key($haystack, fn ($v) => $v == 1));
echo "\n";
var_export(array_find_key($haystack, fn ($v) => $v == 1 ? 1 : 0, true));
echo "\n";
$h = ['a' => 1, 'b' => '1'];
var_export(array_all($h, fn ($v, $k) => $v == 1 ? 1 : 0, false));
echo "\n";
var_export(array_all($h, fn ($v, $k) => $v == 1 ? 1 : 0, true));
echo "\n";
var_export(array_any($h, fn ($v, $k) => $v == 1 ? 1 : 0, false));
echo "\n";
var_export(array_any($h, fn ($v, $k) => $v == 1 ? 1 : 0, true));
echo "\n";
--EXPECT--
1
NULL
0
NULL
true
false
true
false
