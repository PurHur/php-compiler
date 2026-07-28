--TEST--
stdlib str_repeat/str_shuffle/ucfirst/lcfirst/ucwords null — TypeError on 8.4 forward profile (#20080, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
case_repeat_null_forward84.php
--EXPECT--
str_repeat: str_repeat(): Argument #1 ($string) must be of type string, null given
str_shuffle: str_shuffle(): Argument #1 ($string) must be of type string, null given
ucfirst: ucfirst(): Argument #1 ($string) must be of type string, null given
lcfirst: lcfirst(): Argument #1 ($string) must be of type string, null given
ucwords: ucwords(): Argument #1 ($string) must be of type string, null given
