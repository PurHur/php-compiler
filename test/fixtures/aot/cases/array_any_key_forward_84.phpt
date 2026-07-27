--TEST--
AOT: array_any_key()/array_all_key() phantom on PHP 8.4 forward profile (#24000)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('array_any_key') ? "fail_any_key\n" : "ok_any_key\n";
echo function_exists('array_all_key') ? "fail_all_key\n" : "ok_all_key\n";
echo function_exists('array_any') ? "ok_any\n" : "fail_any\n";
echo function_exists('array_all') ? "ok_all\n" : "fail_all\n";
--EXPECT--
ok_any_key
ok_all_key
ok_any
ok_all
