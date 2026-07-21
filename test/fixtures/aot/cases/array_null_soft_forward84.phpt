--TEST--
AOT: array builtins null soft-null on 8.4 forward profile (#21771)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21771_array_null_soft_forward84.php
--EXPECT--
DEP
count OK
DEP
array_merge OK
DEP
array_keys OK
DEP
in_array OK
DEP
array_flip OK
DEP
array_sum OK
DEP
iterator_to_array OK
DEP
array_map OK
