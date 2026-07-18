--TEST--
AOT: Z_PARAM_PATH builtins — null TypeError on 8.4 forward profile (#18817, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
touch(null);
--EXPECT--
--EXPECT_EXIT--
255
