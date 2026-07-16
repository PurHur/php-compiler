--TEST--
AOT: header(null) — TypeError on 8.4 forward profile (#19224, ext/standard/head.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
header(null);
--EXPECT--
--EXPECT_EXIT--
255
