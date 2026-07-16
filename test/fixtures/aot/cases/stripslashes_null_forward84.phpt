--TEST--
AOT: stripslashes null — TypeError on 8.4 forward profile (#19319)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
stripslashes(null);
--EXPECT--
--EXPECT_EXIT--
255
