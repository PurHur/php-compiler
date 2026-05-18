--TEST--
AOT: getenv() for present key via libc
--ENV--
APP_DEBUG=1
--FILE--
<?php
echo getenv('APP_DEBUG'), "\n";
--EXPECT--
1
