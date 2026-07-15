--TEST--
AOT: str_contains null haystack — TypeError on 8.4 forward profile (#19273)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_contains(null, 'x');
--EXPECT--
--EXPECT_EXIT--
255
