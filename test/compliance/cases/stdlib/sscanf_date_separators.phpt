--TEST--
stdlib sscanf() — by-ref %d with literal separators AOT (#27661)
--FILE--
<?php
$n = sscanf("2026-08-04", "%d-%d-%d", $y, $m, $d);
echo "$n:$y:$m:$d\n";
--EXPECT--
3:2026:8:4
