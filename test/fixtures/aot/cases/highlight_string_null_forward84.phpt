--TEST--
AOT: highlight_string(null) — TypeError on 8.4 forward profile (#20262, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
highlight_string(null);
--EXPECT--
--EXPECT_EXIT--
255
