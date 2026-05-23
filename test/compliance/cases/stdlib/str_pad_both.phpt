--TEST--
stdlib str_pad() STR_PAD_BOTH
--FILE--
<?php
echo str_pad('5', 5, '0', 2), "\n";
echo str_pad('hi', 6, '-', 2), "\n";
echo str_pad('x', 4, 'ab', 2), "\n";
echo str_pad('abc', 7, '0', 2), "\n";
--EXPECT--
00500
--hi--
axab
00abc00
