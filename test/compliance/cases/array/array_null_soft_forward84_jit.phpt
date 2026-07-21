--TEST--
JIT: array builtins null — DEP+coerce on 8.4 (#21771, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
issue_21771_array_null_soft_forward84.php
--EXPECT--
DEP
array_merge OK
DEP
in_array OK
DEP
array_flip OK
DEP
array_sum OK
DEP
array_map OK
