--TEST--
stdlib getmygrgid() phantom on PROFILE=8.4 — absent from php-src (#28366, re-#11923)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);
echo function_exists('getmygrgid') ? "fn-fail\n" : "fn-ok\n";
echo function_exists('getmygid') ? "gid-ok\n" : "gid-fail\n";
--EXPECT--
fn-ok
gid-ok
