--TEST--
stdlib restore_include_path() — removed in PHP 8.0+ (#11833, ext/standard/basic_functions.c)
--FILE--
<?php
echo function_exists('restore_include_path') ? "fail\n" : "ok\n";
--EXPECT--
ok
