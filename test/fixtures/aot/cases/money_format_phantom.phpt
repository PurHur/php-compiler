--TEST--
AOT: money_format() phantom — removed php-src 8.0 (#21481)
--FILE--
<?php
echo function_exists('money_format') ? "fail\n" : "ok\n";
echo function_exists('convert_cyr_string') ? "cyr:fail\n" : "cyr:ok\n";
--EXPECT--
ok
cyr:ok
