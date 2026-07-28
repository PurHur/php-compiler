--TEST--
AOT: str_repeat/str_shuffle/ucfirst/lcfirst/ucwords null — TypeError on 8.4 forward profile (#20080, ext/standard/string.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_repeat(null, 1);
--EXPECT--
--EXPECT_EXIT--
255
