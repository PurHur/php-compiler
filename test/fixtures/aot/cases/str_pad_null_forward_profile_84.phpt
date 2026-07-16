--TEST--
AOT: str_pad(null) — TypeError on 8.4 forward profile (#19318, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_pad(null, 5);
--EXPECT--
--EXPECT_EXIT--
255
