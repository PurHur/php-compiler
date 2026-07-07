--TEST--
stdlib array_find family — string builtin callback arity (#17300, #13946, ext/standard/array.c)
--FILE--
<?php
var_export(array_all([1, 2, 3], 'is_int'));
echo "\n";
var_export(array_any(['a', 'bb'], 'strlen'));
echo "\n";
var_export(array_find([1, 2, 3], 'is_int'));
echo "\n";
var_export(array_find_key([1, 2, 3], 'is_int'));
echo "\n";
var_export(array_all_key(['a' => 1], 'is_string'));
echo "\n";
--EXPECT--
true
true
1
0
true
