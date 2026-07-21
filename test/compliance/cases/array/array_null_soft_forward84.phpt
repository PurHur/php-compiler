--TEST--
stdlib array_merge/in_array/array_flip null — TypeError on 8.4 (#21916, inverted #21771)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21771_array_null_soft_forward84.php
--EXPECT--
array_merge TypeError
in_array TypeError
array_flip TypeError
array_sum TypeError
array_map TypeError
