--TEST--
ext function_exists() builtin registry parity VM/JIT (#9239)
--FILE--
<?php
declare(strict_types=1);
var_export(function_exists('array_is_list'));
var_export(function_exists('not_a_real_builtin_xyz'));
echo "\n";
--EXPECT--
truefalse
