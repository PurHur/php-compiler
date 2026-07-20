--TEST--
AOT: readfile(null) — empty-path ValueError on 8.4 forward profile (#21235, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
readfile(null);
--EXPECT--
--EXPECT_EXIT--
255
