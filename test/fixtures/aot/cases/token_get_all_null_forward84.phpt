--TEST--
AOT: token_get_all(null) TypeError on 8.4 forward profile (#19894)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
token_get_all(null);
--EXPECT--
--EXPECT_EXIT--
255
