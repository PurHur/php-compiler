--TEST--
AOT: abs() named num: argument (#23259)
--FILE--
<?php
echo abs(num: -3), "\n";
echo abs(num: 0), "\n";
echo abs(-3), "\n";
--EXPECT--
3
0
3
