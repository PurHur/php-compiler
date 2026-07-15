--TEST--
AOT: strtr(null) — TypeError on 8.4 forward profile (#18981, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
strtr(null, 'ab', 'cd');
--EXPECT--
--EXPECT_EXIT--
255
