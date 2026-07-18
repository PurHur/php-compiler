--TEST--
JIT: strstr/stristr/strpos/strrpos/stripos/strripos null needle TypeError on 8.4 forward profile (#20176, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
str_search_null_needle_forward84.php
--EXPECT--
strstr: strstr(): Argument #2 ($needle) must be of type string, null given
stristr: stristr(): Argument #2 ($needle) must be of type string, null given
strpos: strpos(): Argument #2 ($needle) must be of type string, null given
strrpos: strrpos(): Argument #2 ($needle) must be of type string, null given
stripos: stripos(): Argument #2 ($needle) must be of type string, null given
strripos: strripos(): Argument #2 ($needle) must be of type string, null given
