--TEST--
AOT: touch() — null $filename TypeError (#18245, #18817, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
touch(null);
--EXPECT--
--EXPECT_EXIT--
134
