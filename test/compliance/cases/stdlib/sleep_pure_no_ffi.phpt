--TEST--
stdlib sleep()/usleep() via VmSleepPure when libc FFI disabled (#8922)
--ENV--
PHP_COMPILER_DISABLE_FFI=1
--FILE--
<?php
var_dump(sleep(0));
usleep(0);
echo "ok\n";
--EXPECT--
int(0)
ok
