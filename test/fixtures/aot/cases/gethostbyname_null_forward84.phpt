--TEST--
AOT: gethostbyname(null) DEP+coerce on 8.4 forward profile (#21446, ext/standard/dns.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
gethostbyname(null);
--EXPECT--
