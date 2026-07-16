--TEST--
AOT: define()/defined()/constant(null) — TypeError on 8.4 forward profile (#19652, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
define(null, 1);
--EXPECT--
--EXPECT_EXIT--
255
