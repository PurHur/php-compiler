--TEST--
AOT: implode(",", null) dual-arg TypeError on PROFILE=8.4 (#26278)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
implode(",", null);
--EXPECT--
--EXPECT_EXIT--
255
