--TEST--
AOT: ldexp() phantom — absent from php-src (#24607)
--FILE--
<?php
echo function_exists('ldexp') ? "fail\n" : "ok\n";
--EXPECT--
ok
--EXPECT_EXIT--
0
