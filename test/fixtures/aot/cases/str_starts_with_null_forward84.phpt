--TEST--
AOT: str_starts_with null haystack — TypeError on 8.4 forward profile (#19273)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_starts_with(null, 'x');
--EXPECT--
--EXPECT_EXIT--
255
