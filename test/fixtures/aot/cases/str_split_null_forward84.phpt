--TEST--
AOT: str_split null — TypeError on 8.4 forward profile (#19319)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_split(null);
--EXPECT--
--EXPECT_EXIT--
255
