--TEST--
AOT: error_log(null) TypeError on 8.4 forward profile (#23858, reverts #21446, ext/standard/basic_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_log(null);
--EXPECT--
--EXPECT_EXIT--
255
