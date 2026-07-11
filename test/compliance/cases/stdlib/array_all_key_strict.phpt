--TEST--
stdlib array_all_key()/array_any_key() optional $strict third parameter (#15704, ext/standard/array.c)
--FILE--
<?php
$h = ['a' => 1, 'b' => '1'];
var_export(array_all_key($h, fn ($k, $v) => $v == 1, false));
echo "\n";
var_export(array_all_key($h, fn ($k, $v) => $v == 1, true));
echo "\n";
var_export(array_all_key($h, fn ($k, $v) => $v == 1 ? 1 : 0, false));
echo "\n";
var_export(array_all_key($h, fn ($k, $v) => $v == 1 ? 1 : 0, true));
echo "\n";
var_export(array_any_key($h, fn ($k, $v) => $v == 1 ? 1 : 0, false));
echo "\n";
var_export(array_any_key($h, fn ($k, $v) => $v == 1 ? 1 : 0, true));
echo "\n";
--EXPECT--
true
true
true
false
true
false
