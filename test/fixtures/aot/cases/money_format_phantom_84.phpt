--TEST--
AOT: money_format() phantom under PROFILE=8.4 (#21481)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('money_format') ? "fail\n" : "ok\n";
echo function_exists('convert_cyr_string') ? "cyr:fail\n" : "cyr:ok\n";
--EXPECT--
ok
cyr:ok
