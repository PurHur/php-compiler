--TEST--
AOT: gzcompress(null) - TypeError on 8.4 forward profile (#19332, ext/zlib/zlib.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
gzcompress(null);
--EXPECT--
--EXPECT_EXIT--
255
