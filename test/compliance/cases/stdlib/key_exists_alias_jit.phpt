--TEST--
stdlib key_exists() JIT — alias of array_key_exists() (#5850)
--FILE--
<?php
var_export(key_exists('a', ['a' => 1, 'b' => 2]));
echo "\n";
var_export(key_exists('missing', ['a' => 1]));
echo "\n";
--EXPECT--
true
false
