--TEST--
AOT: array_find family advertised; array_*_key phantoms absent (#17300, #24000)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('array_all') ? "ok_all\n" : "fail_all\n";
echo function_exists('array_any') ? "ok_any\n" : "fail_any\n";
echo function_exists('array_find') ? "ok_find\n" : "fail_find\n";
echo function_exists('array_find_key') ? "ok_find_key\n" : "fail_find_key\n";
echo function_exists('array_any_key') ? "fail_any_key\n" : "ok_any_key\n";
echo function_exists('array_all_key') ? "fail_all_key\n" : "ok_all_key\n";
--EXPECT--
ok_all
ok_any
ok_find
ok_find_key
ok_any_key
ok_all_key
