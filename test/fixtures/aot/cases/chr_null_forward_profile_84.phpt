--TEST--
AOT: chr(null) — TypeError on 8.4 forward profile (#18850, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
chr(null);
--EXPECT--
--EXPECT_EXIT--
134
