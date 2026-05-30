--TEST--
stdlib str_pad() STR_PAD_BOTH
--FILE--
<?php
echo str_pad('5', 4, '0', STR_PAD_BOTH), "\n";
echo str_pad('hi', 6, '-', STR_PAD_BOTH), "\n";
echo str_pad('x', 7, 'ab', STR_PAD_BOTH), "\n";
echo str_pad('test', 10, ' ', STR_PAD_BOTH), "\n";
--EXPECT--
0500
--hi--
abaxaba
   test   
