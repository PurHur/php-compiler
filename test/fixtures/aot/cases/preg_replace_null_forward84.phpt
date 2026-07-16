--TEST--
AOT: preg_replace null subject TypeError on 8.4 forward profile (#19241)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
preg_replace('//', 'x', null);
--EXPECT--
--EXPECT_EXIT--
255
