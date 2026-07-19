--TEST--
AOT ini_set(null) — TypeError forward 8.4 profile (#20361)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
ini_set(null, '1');
--EXPECT--
--EXPECT_EXIT--
255
