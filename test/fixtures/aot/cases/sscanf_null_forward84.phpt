--TEST--
AOT: sscanf(null) TypeError on 8.4 forward profile (#19894)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
sscanf(null, '%s');
--EXPECT--
--EXPECT_EXIT--
255
