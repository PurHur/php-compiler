--TEST--
AOT sleep()/usleep() — numeric-string and float seconds coercion (issue #4323)
--FILE--
<?php
echo sleep("0"), "\n";
usleep("0");
echo "ok\n";
echo sleep(0.9), "\n";
--EXPECT--
0
ok
0
