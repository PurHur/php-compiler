--TEST--
mbstring null $string — soft-null length/substr/strpos/strtolower/strtoupper/convert_encoding on 8.4 (#21197, #21282, #21313)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
null_string_args_typeerror_84.php
--EXPECT--
mb_strlen: OK 0
mb_substr: OK ''
mb_strpos: OK false
mb_strtolower: OK ''
mb_strtoupper: OK ''
mb_convert_encoding: OK ''
