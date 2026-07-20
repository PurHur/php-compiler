--TEST--
AOT: Z_PARAM_PATH builtins — null soft-coerces on 8.4 forward profile (#20362, ext/standard/filestat.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo var_export(@touch(null), true), "\n";
--EXPECT--
false
