--TEST--
stdlib str_pad() STR_PAD_LEFT
--FILE--
<?php
echo str_pad('5', 4, '0', STR_PAD_LEFT), "\n";
echo str_pad('hi', 6, '-', STR_PAD_LEFT), "\n";
--EXPECT--
0005
----hi
