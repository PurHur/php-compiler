--TEST--
AOT: str_increment(null) — TypeError on 8.4 forward profile (#21005, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_increment(null);
--EXPECT--
--EXPECT_EXIT--
255
