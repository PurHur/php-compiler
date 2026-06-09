--TEST--
stdlib sleep()/usleep() via VmSleepNative libc FFI without host PHP (#4860)
--FILE--
<?php
$t0 = hrtime(true);
usleep(10000);
echo hrtime(true) > $t0 ? "ok\n" : "fail\n";
echo sleep(0), "\n";
try {
    sleep(-1);
    echo "neg-sleep-uncaught\n";
} catch (ValueError $e) {
    echo "neg-sleep-ve\n";
}
try {
    usleep(-1);
    echo "neg-usleep-uncaught\n";
} catch (ValueError $e) {
    echo "neg-usleep-ve\n";
}
--EXPECT--
ok
0
neg-sleep-ve
neg-usleep-ve
