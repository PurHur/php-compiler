--TEST--
mbstring null $string operands TypeError on 8.4 forward profile (#19297, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
null_string_args_typeerror_84.php
--EXPECT--
mb_strlen: mb_strlen(): Argument #1 ($string) must be of type string, null given
mb_substr: mb_substr(): Argument #1 ($string) must be of type string, null given
mb_strpos: mb_strpos(): Argument #1 ($haystack) must be of type string, null given
mb_strtolower: mb_strtolower(): Argument #1 ($string) must be of type string, null given
mb_strtoupper: mb_strtoupper(): Argument #1 ($string) must be of type string, null given
mb_convert_encoding: mb_convert_encoding(): Argument #1 ($string) must be of type array|string, null given
