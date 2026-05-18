--TEST--
stdlib getenv() JIT/AOT path
--ENV--
APP_DEBUG=1
--FILE--
<?php
echo getenv('APP_DEBUG_NONEXISTENT') === false ? "false\n" : "set\n";
echo getenv('APP_DEBUG'), "\n";
$ok = putenv('APP_ENV=production');
echo $ok ? getenv('APP_ENV') : 'fail', "\n";
--EXPECT--
false
1
production
