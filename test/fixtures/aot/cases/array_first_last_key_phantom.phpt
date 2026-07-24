--TEST--
AOT: array_first_key()/array_last_key() — never in php-src (#22793)
--FILE--
<?php
echo function_exists('array_first_key') ? "fail_first\n" : "ok_first\n";
echo function_exists('array_last_key') ? "fail_last\n" : "ok_last\n";
echo function_exists('array_key_first') ? "ok_key_first\n" : "fail_key_first\n";
--EXPECT--
ok_first
ok_last
ok_key_first
