--TEST--
stdlib memcmp() phantom — absent from php-src (#25359)
--FILE--
<?php
echo function_exists('memcmp') ? "fail\n" : "ok\n";
--EXPECT--
ok
