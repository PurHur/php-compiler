--TEST--
stdlib str_pad() STR_PAD_LEFT
--FILE--
<?php
echo str_pad('5', 4, '0', 1), "\n";
echo str_pad('hi', 6, '-', 1), "\n";
--EXPECT--
0005
----hi
