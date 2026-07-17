--TEST--
AOT: substr_compare(null) — TypeError on 8.4 forward profile (#20164, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
substr_compare(null, 'a', 0);
--EXPECT--
--EXPECT_EXIT--
255
