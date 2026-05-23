--TEST--
stdlib str_pad() STR_PAD_BOTH
--FILE--
<?php
echo str_pad('5', 4, '0', 2), "\n";
echo str_pad('hi', 6, '-', 2), "\n";
echo str_pad('x', 7, 'ab', 2), "\n";
echo str_pad('test', 10, ' ', 2), "\n";
--EXPECT--
0500
--hi--
abaxaba
   test   
