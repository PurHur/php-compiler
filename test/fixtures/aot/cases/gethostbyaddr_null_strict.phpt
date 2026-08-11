--TEST--
AOT: gethostbyaddr(null) TypeError under strict_types (#29809, ext/standard/dns.c)
--FILE--
<?php
declare(strict_types=1);

gethostbyaddr(null);
--EXPECT--
--EXPECT_EXIT--
255
