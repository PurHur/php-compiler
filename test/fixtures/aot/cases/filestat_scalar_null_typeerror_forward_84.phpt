--TEST--
AOT: filesize(null) TypeError on 8.4 forward profile (#20474, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
filesize(null);
--EXPECT--
--EXPECT_EXIT--
255
