--TEST--
AOT: abs() clears signed zero (#23978)
--FILE--
<?php
$pos = abs(-0.0);
$neg = -0.0;
echo bin2hex(pack('d', $pos)), "\n";
echo bin2hex(pack('d', $neg)), "\n";
echo abs(-7), "\n";
--EXPECT--
0000000000000000
0000000000000080
7
