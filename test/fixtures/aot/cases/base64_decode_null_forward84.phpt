--TEST--
AOT: base64_decode/hex2bin/quoted_printable null — TypeError on 8.4 forward profile (#19283)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
base64_decode(null);
--EXPECT--
--EXPECT_EXIT--
255
