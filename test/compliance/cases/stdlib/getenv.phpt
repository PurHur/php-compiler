--TEST--
stdlib getenv() and putenv()
--ENV--
APP_DEBUG=1
--FILE--
<?php
$debug = getenv('APP_DEBUG');
echo $debug === false ? 'missing' : $debug, "\n";
echo getenv('APP_DEBUG_NONEXISTENT') === false ? 'false' : 'set', "\n";
$ok = putenv('APP_ENV=production');
echo $ok ? getenv('APP_ENV') : 'fail', "\n";
--EXPECT--
1
false
production
