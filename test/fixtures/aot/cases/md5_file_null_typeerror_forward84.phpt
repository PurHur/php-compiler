--TEST--
AOT: md5_file(null)/hash_file null — TypeError on 8.4 forward profile (#21062)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
md5_file(null);
--EXPECT--
--EXPECT_EXIT--
255
