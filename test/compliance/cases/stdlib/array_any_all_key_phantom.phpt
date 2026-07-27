--TEST--
stdlib array_any_key()/array_all_key() — never in php-src (#24000, ext/standard/array.c)
--FILE--
<?php
echo function_exists('array_any_key') ? "fail_any\n" : "ok_any\n";
echo function_exists('array_all_key') ? "fail_all\n" : "ok_all\n";
--EXPECT--
ok_any
ok_all
