--TEST--
AOT: proc_nice() via libc nice(3) (#5181)
--FILE--
<?php
$ok = proc_nice(0);
echo is_bool($ok) ? "bool\n" : "bad\n";
echo "ok\n";
--EXPECT--
bool
ok
