--TEST--
JIT: str_contains/str_starts_with/str_ends_with null haystack TypeError on 8.4 forward profile (#19273)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
str_contains_null_haystack_forward84.php
--EXPECT--
str_contains: str_contains(): Argument #1 ($haystack) must be of type string, null given
str_starts_with: str_starts_with(): Argument #1 ($haystack) must be of type string, null given
str_ends_with: str_ends_with(): Argument #1 ($haystack) must be of type string, null given
