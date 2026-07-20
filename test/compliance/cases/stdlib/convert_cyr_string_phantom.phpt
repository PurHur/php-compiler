--TEST--
stdlib convert_cyr_string() — removed in php-src 8.0; phantom on 8.2 reference (#21481)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo function_exists('convert_cyr_string') ? "fail\n" : "ok\n";
--EXPECT--
ok
