--TEST--
mbstring null $string JIT — soft-null length/substr/strpos on 8.4 (#21197); others TypeError (#19297)
--ENV--
PHP_COMPILER_PROFILE=8.4
--JIT--
--RUNFILE--
null_string_args_typeerror_84.php
--EXPECT--
mb_strlen: OK 0
mb_substr: OK ''
mb_strpos: OK false
mb_strtolower: mb_strtolower(): Argument #1 ($string) must be of type string, null given
mb_strtoupper: mb_strtoupper(): Argument #1 ($string) must be of type string, null given
mb_convert_encoding: mb_convert_encoding(): Argument #1 ($string) must be of type array|string, null given
