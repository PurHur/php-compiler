--TEST--
AOT sleep()/usleep() — null coerces on 8.4 forward profile (#19077)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo sleep(null), "\n";
usleep(null);
echo "ok\n";
--EXPECT--
0
ok
