--TEST--
AOT: touch() — null $filename soft-coerces (#20362 supersedes #18245, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo var_export(@touch(null), true), "\n";
--EXPECT--
false
