--TEST--
stdlib str_replace()/str_ireplace() null $search TypeError on 8.4 forward profile (#20173, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
str_replace_null_search_forward_profile84.php
--EXPECT--
str_replace: str_replace(): Argument #1 ($search) must be of type array|string, null given
str_ireplace: str_ireplace(): Argument #1 ($search) must be of type array|string, null given
