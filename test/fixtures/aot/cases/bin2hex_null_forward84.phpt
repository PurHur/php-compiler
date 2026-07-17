--TEST--
AOT: bin2hex(null) — TypeError on 8.4 forward profile (#20154, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
bin2hex(null);
--EXPECT--
--EXPECT_EXIT--
255
