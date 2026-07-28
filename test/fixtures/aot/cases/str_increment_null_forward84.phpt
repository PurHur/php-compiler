--TEST--
AOT: str_increment(null) — ValueError on 8.4 forward profile (#24179, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_increment(null);
--EXPECT--
--EXPECT_EXIT--
255
