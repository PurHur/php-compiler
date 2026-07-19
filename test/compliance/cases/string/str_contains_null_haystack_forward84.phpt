--TEST--
stdlib str_contains/str_starts_with/str_ends_with null haystack soft-null on 8.4 forward profile (#21187, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
str_contains_null_haystack_forward84.php
--EXPECT--
str_contains: OK=false depr=1
str_starts_with: OK=false depr=1
str_ends_with: OK=false depr=1
