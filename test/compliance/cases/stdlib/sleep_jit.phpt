--TEST--
JIT: sleep() and usleep() with zero delay
--FILE--
<?php
$r = sleep(0);
echo $r === 0 ? "sleep-ok\n" : "sleep-fail\n";
usleep(0);
echo "usleep-ok\n";
--EXPECT--
sleep-ok
usleep-ok
