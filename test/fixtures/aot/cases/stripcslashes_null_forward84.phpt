--TEST--
AOT: stripcslashes null — TypeError on 8.4 forward profile (#19432)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
stripcslashes(null);
--EXPECT--
--EXPECT_EXIT--
255
