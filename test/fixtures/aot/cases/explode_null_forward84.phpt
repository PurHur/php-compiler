--TEST--
AOT: explode null haystack — TypeError on 8.4 forward profile (#19309)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
explode(',', null);
--EXPECT--
--EXPECT_EXIT--
255
