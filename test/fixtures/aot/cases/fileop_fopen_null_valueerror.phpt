--TEST--
AOT: fopen(null) — TypeError on 8.4 forward profile (#21076, ext/standard/file.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
fopen(null, 'r');
--EXPECT--
--EXPECT_EXIT--
255
