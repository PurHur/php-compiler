--TEST--
AOT: ip2long/inet_pton/inet_ntop(null) TypeError under strict_types (#29785, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

ip2long(null);
--EXPECT--
--EXPECT_EXIT--
255
