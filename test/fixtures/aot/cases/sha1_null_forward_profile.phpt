--TEST--
AOT: sha1(null) — TypeError on 8.4 forward profile (#19255, ext/standard/sha1.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
sha1(null);
--EXPECT--
--EXPECT_EXIT--
255
