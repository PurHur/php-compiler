--TEST--
stdlib array_key_exists()/key_exists() bool key coercion (ext/standard/array.c)
--FILE--
<?php
var_export(array_key_exists(true, [1 => 'x']));
echo "\n";
var_export(array_key_exists(false, [0 => 1, 1 => 2]));
echo "\n";
var_export(key_exists(false, [0 => 1]));
echo "\n";
--EXPECT--
true
true
true
