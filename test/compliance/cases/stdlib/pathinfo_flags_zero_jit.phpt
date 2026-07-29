--TEST--
JIT: pathinfo() flags 0 → empty string (#24941)
--FILE--
<?php
$z = pathinfo('/a/b.txt', 0);
echo 'flags0=', var_export($z, true), ' type=', gettype($z), "\n";
--EXPECT--
flags0='' type=string
