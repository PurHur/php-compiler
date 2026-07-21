--TEST--
JIT: in_array/array_merge/array_flip/array_map/array_sum null — TypeError (#21916, ext/standard/array.c)
--JIT--
--RUNFILE--
issue_21916_array_null_typeerror.php
--EXPECT--
in_array TypeError: in_array(): Argument #2 ($haystack) must be of type array, null given
array_merge TypeError: array_merge(): Argument #1 must be of type array, null given
array_flip TypeError: array_flip(): Argument #1 ($array) must be of type array, null given
array_map TypeError: array_map(): Argument #2 ($array) must be of type array, null given
array_sum TypeError: array_sum(): Argument #1 ($array) must be of type array, null given
