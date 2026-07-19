--TEST--
AOT setcookie(null) — TypeError forward 8.4 profile (#21003)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
setcookie(null);
--EXPECT--
--EXPECT_EXIT--
255
