--TEST--
AOT: strtok(null) soft-null on 8.4 forward profile (#21195 / #29784, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo false === strtok(null, '.') ? "OK\n" : "BAD\n";
--EXPECT--
OK
