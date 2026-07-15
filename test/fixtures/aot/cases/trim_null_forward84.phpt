--TEST--
AOT: trim/ltrim/rtrim/chop null — TypeError on 8.4 forward profile (#19254)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
trim(null);
--EXPECT--
--EXPECT_EXIT--
255
