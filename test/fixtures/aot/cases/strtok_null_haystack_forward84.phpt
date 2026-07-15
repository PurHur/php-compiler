--TEST--
AOT: strtok(null) — TypeError on 8.4 forward profile (#19242, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
strtok(null, '.');
--EXPECT--
--EXPECT_EXIT--
255
