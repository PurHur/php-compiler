--TEST--
stdlib substr_count/substr_replace(null) TypeError on 8.4 forward profile JIT (#19282)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
substr_count_replace_null_forward84.php
--EXPECT--
substr_count: substr_count(): Argument #1 ($haystack) must be of type string, null given
substr_replace: substr_replace(): Argument #1 ($string) must be of type array|string, null given
