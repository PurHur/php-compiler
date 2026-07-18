--TEST--
AOT: password_get_info(null) — TypeError on 8.4 forward profile (#20672, ext/standard/password.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
password_get_info(null);
--EXPECT--
--EXPECT_EXIT--
255
