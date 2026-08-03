--TEST--
AOT: sys_getloadavg() returns 3-element array (#27294)
--FILE--
<?php
echo "before\n";
$a = sys_getloadavg();
echo "after\n";
echo is_array($a) ? count($a) : "notarr", "\n";
--EXPECT--
before
after
3
--EXPECT_EXIT--
0
