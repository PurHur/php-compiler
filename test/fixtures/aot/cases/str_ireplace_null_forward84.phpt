--TEST--
AOT: str_ireplace null subject TypeError on 8.4 forward profile (#19241)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_ireplace('a', 'b', null);
--EXPECT--
--EXPECT_EXIT--
255
