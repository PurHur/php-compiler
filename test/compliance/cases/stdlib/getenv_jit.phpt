--TEST--
stdlib getenv() JIT/AOT path
--ENV--
APP_DEBUG=1
--FILE--
<?php
echo getenv('APP_DEBUG_NONEXISTENT') === false ? "false\n" : "set\n";
echo getenv('APP_DEBUG'), "\n";
putenv('APP_ENV=production');
echo getenv('APP_ENV'), "\n";
--EXPECT--
false
1
production
