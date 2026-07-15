--TEST--
AOT: strrev null — TypeError on 8.4 forward profile (#19276)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
strrev(null);
--EXPECT--
--EXPECT_EXIT--
255
