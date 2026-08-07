--TEST--
stdlib class_uses_recursive() phantom on PROFILE=8.4 — absent from php-src (#28365, re-#12816)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('class_uses_recursive') ? "fn-fail\n" : "fn-ok\n";
echo function_exists('class_uses') ? "uses-ok\n" : "uses-fail\n";
--EXPECT--
fn-ok
uses-ok
