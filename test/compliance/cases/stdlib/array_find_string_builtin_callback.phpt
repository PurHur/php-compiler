--TEST--
stdlib array_find family — string builtin callback unary arity (#17300, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(array_all([1, 2, 3], 'is_int'));
echo "\n";
var_export(array_all_key(['a' => 1], 'is_string'));
echo "\n";
var_export(array_find_key([1, 2, 3], 'is_int'));
echo "\n";
var_export(array_any(['a', 'bb'], 'strlen'));
echo "\n";
--EXPECT--
true
true
0
true
