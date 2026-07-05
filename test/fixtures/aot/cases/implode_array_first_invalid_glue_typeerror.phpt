--TEST--
AOT: implode() — array-first invalid glue cites argument #2 (#16401, ext/standard/string.c)
--FILE--
<?php
implode([], 1);
--EXPECT--
--EXPECT_EXIT--
134
