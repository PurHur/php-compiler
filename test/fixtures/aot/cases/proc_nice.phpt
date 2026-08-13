--TEST--
AOT: proc_nice() via ProcNiceJitHelper (#5181, #30615)
--FILE--
<?php
$ok = proc_nice(0);
echo is_bool($ok) ? "bool\n" : "bad\n";
echo "ok\n";
--EXPECT--
bool
ok
