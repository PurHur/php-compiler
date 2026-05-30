--TEST--
AOT: str_pad() STR_PAD_RIGHT, STR_PAD_LEFT, and STR_PAD_BOTH
--FILE--
<?php
echo str_pad('5', 4, '0'), "\n";
echo str_pad('5', 4, '0', STR_PAD_LEFT), "\n";
echo str_pad('5', 4, '0', STR_PAD_BOTH), "\n";
echo str_pad('hi', 6, '-', STR_PAD_BOTH), "\n";
echo str_pad('x', 7, 'ab', STR_PAD_BOTH), "\n";
--EXPECT--
5000
0005
0500
--hi--
abaxaba
