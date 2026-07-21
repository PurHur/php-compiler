--TEST--
stdlib array null TypeError also under PROFILE=8.4 (#21916, inverted #21771 soft claim)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
issue_21916_array_null_typeerror.php
--EXPECT--
in_array TypeError: in_array(): Argument #2 ($haystack) must be of type array, null given
array_merge TypeError: array_merge(): Argument #1 must be of type array, null given
array_flip TypeError: array_flip(): Argument #1 ($array) must be of type array, null given
array_map TypeError: array_map(): Argument #2 ($array) must be of type array, null given
array_sum TypeError: array_sum(): Argument #1 ($array) must be of type array, null given
