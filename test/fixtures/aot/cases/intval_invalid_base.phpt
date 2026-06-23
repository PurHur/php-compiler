--TEST--
AOT: intval() invalid $base returns 0 (issue #10672)
--FILE--
<?php
echo intval('ff', 37), "\n";
echo intval('10', 1), "\n";
echo intval('ff', 16), "\n";
echo intval('0x10', 0), "\n";
echo intval(42, 37), "\n";
--EXPECT--
0
0
255
16
42
