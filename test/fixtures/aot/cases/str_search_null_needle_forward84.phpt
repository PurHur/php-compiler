--TEST--
AOT: strstr/strpos/strrpos/stristr null needle — TypeError on 8.4 forward profile (#20176, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
strpos('abc', null);
--EXPECT--
--EXPECT_EXIT--
255
