--TEST--
stdlib crc32c() hello vector matches php-src (#18020, ext/standard/crc32.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
echo crc32c('hello'), "\n";
--EXPECT--
2591144780
