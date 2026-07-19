--TEST--
AOT ini_get(null) — TypeError forward 8.4 profile (#20361)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ini_get(null);
--EXPECT--
--EXPECT_EXIT--
255
