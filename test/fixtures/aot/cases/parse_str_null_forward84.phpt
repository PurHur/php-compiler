--TEST--
AOT: parse_str(null) — TypeError on 8.4 forward profile (#20113, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
parse_str(null, $o);
--EXPECT--
--EXPECT_EXIT--
255
