--TEST--
AOT iconv() null encoding/string TypeError on 8.4 forward profile (#19387)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
iconv(null, 'UTF-8', 'x');
--EXPECT--
--EXPECT_EXIT--
255
