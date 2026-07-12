--TEST--
AOT: touch() — null $filename TypeError (#18245, ext/standard/file.c)
--FILE--
<?php
touch(null);
--EXPECT--
--EXPECT_EXIT--
134
