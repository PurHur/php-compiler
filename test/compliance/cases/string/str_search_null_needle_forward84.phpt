--TEST--
stdlib strstr/strpos null needle soft-null; siblings TypeError on 8.4 (#20176/#21189)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
str_search_null_needle_forward84.php
--EXPECT--
strstr: OK 'abc'
stristr: stristr(): Argument #2 ($needle) must be of type string, null given
strpos: OK 0
strrpos: strrpos(): Argument #2 ($needle) must be of type string, null given
stripos: stripos(): Argument #2 ($needle) must be of type string, null given
strripos: strripos(): Argument #2 ($needle) must be of type string, null given
