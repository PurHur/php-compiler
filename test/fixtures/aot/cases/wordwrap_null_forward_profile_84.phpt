--TEST--
AOT: wordwrap(null) — TypeError on 8.4 forward profile (#19318, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
wordwrap(null);
--EXPECT--
--EXPECT_EXIT--
255
