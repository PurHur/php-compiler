--TEST--
AOT: password_needs_rehash(null) — TypeError on 8.4 forward profile (#18655, ext/standard/password.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
password_needs_rehash(null, PASSWORD_DEFAULT);
--EXPECT--
--EXPECT_EXIT--
255
