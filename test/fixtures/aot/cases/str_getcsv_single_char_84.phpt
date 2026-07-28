--TEST--
AOT str_getcsv() multi-byte separator ValueError under PROFILE=8.4 (#24148)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
str_getcsv('a,b', ',,', '"', '"');
--EXPECT--
--EXPECT_EXIT--
255
