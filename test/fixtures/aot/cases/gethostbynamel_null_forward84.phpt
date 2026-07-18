--TEST--
AOT: gethostbynamel(null) TypeError on 8.4 forward profile (#20555, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
gethostbynamel(null);
--EXPECT--
--EXPECT_EXIT--
255
