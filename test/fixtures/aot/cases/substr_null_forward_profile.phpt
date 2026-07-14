--TEST--
AOT: substr(null) — TypeError on 8.4 forward profile (#18980, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
substr(null, 0);
--EXPECT--
--EXPECT_EXIT--
255
