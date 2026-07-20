--TEST--
AOT: strcmp(null) soft-null on 8.4 forward profile (#21190)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo 0 === strcmp(null, '') ? "OK\n" : "BAD\n";
--EXPECT--
OK
