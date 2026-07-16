--TEST--
AOT: substr_replace null — TypeError on 8.4 forward profile (#19282)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
substr_replace(null, 'x', 0);
--EXPECT--
--EXPECT_EXIT--
255
