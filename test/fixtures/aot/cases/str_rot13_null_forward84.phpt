--TEST--
AOT: str_rot13 null — TypeError on 8.4 forward profile (#19309)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_rot13(null);
--EXPECT--
--EXPECT_EXIT--
255
