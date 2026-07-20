--TEST--
AOT: convert_cyr_string() phantom — removed php-src 8.0 (#21481)
--FILE--
<?php
echo function_exists('convert_cyr_string') ? "fail\n" : "ok\n";
--EXPECT--
ok
