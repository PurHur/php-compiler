--TEST--
AOT: urlencode(null) — TypeError on 8.4 forward profile (#19272, ext/standard/url.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
urlencode(null);
--EXPECT--
--EXPECT_EXIT--
255
