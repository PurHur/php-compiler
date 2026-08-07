--TEST--
stdlib get_object_id() phantom on PROFILE=8.4 — absent from php-src (#28405, re-#3537)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('get_object_id') ? "fn-fail\n" : "fn-ok\n";
echo function_exists('spl_object_id') ? "spl-ok\n" : "spl-fail\n";
--EXPECT--
fn-ok
spl-ok
