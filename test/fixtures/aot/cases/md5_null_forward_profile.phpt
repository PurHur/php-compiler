--TEST--
AOT: md5(null)/sha1(null) — TypeError on 8.4 forward profile (#19255, ext/standard/md5.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
md5(null);
--EXPECT--
--EXPECT_EXIT--
255
