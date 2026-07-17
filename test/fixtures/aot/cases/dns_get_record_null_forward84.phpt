--TEST--
AOT: dns_get_record(null) TypeError on 8.4 forward profile (#18786, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
dns_get_record(null);
--EXPECT--
--EXPECT_EXIT--
255
