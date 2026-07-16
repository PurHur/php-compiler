--TEST--
AOT: substr_count null — TypeError on 8.4 forward profile (#19282)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
substr_count(null, 'a');
--EXPECT--
--EXPECT_EXIT--
255
