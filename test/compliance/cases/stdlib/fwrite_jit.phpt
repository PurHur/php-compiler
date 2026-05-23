--TEST--
JIT: fwrite() to STDOUT/STDERR via write(2)
--FILE--
<?php
fwrite(STDOUT, "ok\n");
$n = fwrite(STDERR, "e");
echo 1 === $n ? "1" : "0";
--EXPECT--
ok
1
