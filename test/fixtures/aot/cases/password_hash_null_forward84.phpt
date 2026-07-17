--TEST--
AOT: password_hash(null) — TypeError on 8.4 forward profile (#20174, ext/standard/password.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
password_hash(null, PASSWORD_DEFAULT);
--EXPECT--
--EXPECT_EXIT--
255
