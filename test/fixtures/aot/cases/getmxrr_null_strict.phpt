--TEST--
AOT: getmxrr(null) TypeError under strict_types (#29810, ext/standard/dns.c)
--FILE--
<?php
declare(strict_types=1);

$hosts = [];
getmxrr(null, $hosts);
--EXPECT--
--EXPECT_EXIT--
255
