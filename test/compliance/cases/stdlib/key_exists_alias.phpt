--TEST--
stdlib key_exists() — alias of array_key_exists() (#5850, ext/standard/array.c)
--FILE--
<?php
var_export(function_exists('key_exists'));
echo "\n";
var_export(function_exists('array_key_exists'));
echo "\n";
var_export(key_exists('k', ['k' => 1]));
echo "\n";
var_export(array_key_exists('k', ['k' => 1]));
echo "\n";
var_export(key_exists(0, [0 => 'zero']));
echo "\n";
--EXPECT--
true
true
true
true
true
