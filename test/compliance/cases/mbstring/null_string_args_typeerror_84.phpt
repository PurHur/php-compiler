--TEST--
mbstring null $string — soft-null length/substr/strpos/strtolower/convert_encoding on 8.4 (#21197, #21282); strtoupper TypeError
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
null_string_args_typeerror_84.php
--EXPECT--
mb_strlen: OK 0
mb_substr: OK ''
mb_strpos: OK false
mb_strtolower: OK ''
mb_strtoupper: mb_strtoupper(): Argument #1 ($string) must be of type string, null given
mb_convert_encoding: OK ''
