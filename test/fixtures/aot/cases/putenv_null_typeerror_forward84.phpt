--TEST--
AOT putenv(null) — TypeError forward 8.4 profile (#21004)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
putenv(null);
--EXPECT--
--EXPECT_EXIT--
255
