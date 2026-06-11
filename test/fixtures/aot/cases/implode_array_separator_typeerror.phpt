--TEST--
AOT: implode() — TypeError when separator is array (#4160, ext/standard/string.c)
--FILE--
<?php
implode(['x'], ['a', 'b']);
--EXPECT--
--EXPECT_EXIT--
134
