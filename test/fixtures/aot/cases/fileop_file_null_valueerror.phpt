--TEST--
AOT: file(null) — ValueError Path cannot be empty (#19162, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
file(null);
--EXPECT--
--EXPECT_EXIT--
255
