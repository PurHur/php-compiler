--TEST--
AOT: strcmp family null — TypeError on 8.4 forward profile (#19298)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
strcmp(null, 'a');
--EXPECT--
--EXPECT_EXIT--
255
