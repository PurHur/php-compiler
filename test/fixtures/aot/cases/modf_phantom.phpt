--TEST--
AOT: modf() phantom — absent from php-src (#25359)
--FILE--
<?php
echo function_exists('modf') ? "fail\n" : "ok\n";
--EXPECT--
ok
--EXPECT_EXIT--
0
