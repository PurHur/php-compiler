--TEST--
AOT: getenv() via libc (static binary)
--ENV--
APP_DEBUG=1
--FILE--
<?php
echo getenv('APP_DEBUG_NONEXISTENT') === false ? "false\n" : "set\n";
echo getenv('APP_DEBUG'), "\n";
--EXPECT--
false
1
