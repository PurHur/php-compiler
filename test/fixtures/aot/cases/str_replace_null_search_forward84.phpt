--TEST--
AOT: str_replace null $search TypeError on 8.4 forward profile (#20173)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_replace(null, 'b', 'hay');
--EXPECT--
--EXPECT_EXIT--
255
