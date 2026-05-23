--TEST--
AOT: str_pad() STR_PAD_RIGHT, STR_PAD_LEFT, STR_PAD_BOTH
--FILE--
<?php
echo str_pad('5', 4, '0'), "\n";
echo str_pad('5', 4, '0', 0), "\n";
echo str_pad('5', 5, '0', 2), "\n";
echo str_pad('x', 4, 'ab', 2), "\n";
--EXPECT--
5000
0005
00500
axab
