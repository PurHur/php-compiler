--TEST--
AOT: substr_count(null $needle) — soft-null then Uncaught ValueError empty on 8.4 (#29421)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Uncaught: AOT try/catch does not yet catch helper ValueError (peer str_increment #26264 fixture).
substr_count('aaa', null);
--EXPECT--
--EXPECT_EXIT--
255
