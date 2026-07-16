--TEST--
AOT: basename null — TypeError on 8.4 forward profile (#19256)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
basename(null);
--EXPECT--
--EXPECT_EXIT--
255
