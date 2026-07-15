--TEST--
AOT: mb_strlen null — TypeError on 8.4 forward profile (#19297)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
mb_strlen(null);
--EXPECT--
--EXPECT_EXIT--
    10|255
