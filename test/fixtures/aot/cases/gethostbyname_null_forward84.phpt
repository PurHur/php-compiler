--TEST--
AOT: gethostbyname(null) TypeError on 8.4 forward profile (#23858, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
gethostbyname(null);
--EXPECT--
--EXPECT_EXIT--
255
