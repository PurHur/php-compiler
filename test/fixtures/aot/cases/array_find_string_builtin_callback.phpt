--TEST--
AOT: array_find family string builtin callback unary arity (#17300)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(array_all([1, 2, 3], 'is_int'));
echo "\n";
--EXPECT--
false
