--TEST--
AOT: trim(null) — TypeError on 8.4 forward profile (#21350, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
trim(null);
--EXPECT--
--EXPECT_EXIT--
255
