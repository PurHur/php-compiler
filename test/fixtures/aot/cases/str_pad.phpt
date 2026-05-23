--TEST--
AOT: str_pad() STR_PAD_RIGHT, STR_PAD_LEFT, and STR_PAD_BOTH
--FILE--
<?php
echo str_pad('5', 4, '0'), "\n";
echo str_pad('5', 4, '0', 0), "\n";
echo str_pad('a', 5, '_', 2), "\n";
echo str_pad('ab', 7, 'xy', 2), "\n";
--EXPECT--
5000
0005
__a__
xyabxyx
