--TEST--
stdlib str_replace()/str_ireplace()/preg_replace() null subject TypeError on 8.4 forward profile (#18914, #19241)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
str_replace_null_forward_profile84.php
--EXPECT--
str_replace: str_replace(): Argument #3 ($subject) must be of type array|string, null given
str_ireplace: str_ireplace(): Argument #3 ($subject) must be of type array|string, null given
preg_replace: preg_replace(): Argument #3 ($subject) must be of type array|string, null given
