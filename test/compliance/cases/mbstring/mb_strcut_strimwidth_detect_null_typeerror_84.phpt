--TEST--
mb_detect_encoding null $string soft-null on 8.4 profile (#21516; strcut/strimwidth soft-null #21430)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
mb_strcut_strimwidth_detect_null_typeerror_84.php
--EXPECT--
mb_detect_encoding: OK 'ASCII'
