--TEST--
AOT: str_ireplace null $search TypeError on 8.4 forward profile (#20173)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_ireplace(null, 'b', 'Hay');
--EXPECT--
--EXPECT_EXIT--
255
