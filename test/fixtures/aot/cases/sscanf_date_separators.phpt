--TEST--
AOT: sscanf by-ref %d-%d-%d date separators (#27661)
--FILE--
<?php
$n = sscanf("2026-08-04", "%d-%d-%d", $y, $m, $d);
echo "$n:$y:$m:$d\n";
--EXPECT--
3:2026:8:4
