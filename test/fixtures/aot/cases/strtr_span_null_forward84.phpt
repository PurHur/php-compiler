--TEST--
AOT: strtr null — TypeError on 8.4 forward profile (#19284)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
strtr(null, 'a', 'b');
--EXPECT--
--EXPECT_EXIT--
255
