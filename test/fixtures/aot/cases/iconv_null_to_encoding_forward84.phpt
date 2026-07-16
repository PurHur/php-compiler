--TEST--
AOT iconv() null to_encoding TypeError on 8.4 forward profile (#19387)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
iconv('UTF-8', null, 'x');
--EXPECT--
--EXPECT_EXIT--
255
