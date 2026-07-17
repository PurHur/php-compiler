--TEST--
mb_strcut/mb_strimwidth/mb_detect_encoding null $string TypeError on 8.4 profile (#20225, ext/mbstring/mbstring.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
mb_strcut_strimwidth_detect_null_typeerror_84.php
--EXPECT--
mb_strcut: mb_strcut(): Argument #1 ($string) must be of type string, null given
mb_strimwidth: mb_strimwidth(): Argument #1 ($string) must be of type string, null given
mb_detect_encoding: mb_detect_encoding(): Argument #1 ($string) must be of type string, null given
