--TEST--
JIT: substr_compare(null haystack/needle) TypeError on 8.4 forward profile (#20164, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
substr_compare_null_forward84.php
--EXPECT--
haystack: substr_compare(): Argument #1 ($haystack) must be of type string, null given
needle: substr_compare(): Argument #2 ($needle) must be of type string, null given
