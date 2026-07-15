--TEST--
AOT: escapeshellarg null — TypeError on 8.4 forward profile (#19333)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
escapeshellarg(null);
--EXPECT--
--EXPECT_EXIT--
255
