--TEST--
AOT: is_file(null) soft-coerces on 8.4 forward profile (#20362 supersedes #20474, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo var_export(@is_file(null), true), "\n";
--EXPECT--
false
