--TEST--
stdlib str_pad()
--FILE--
<?php
echo str_pad('5', 4, '0'), "\n";
echo str_pad('5', 4, '0', 0), "\n";
echo str_pad('hi', 5), "\n";
echo str_pad('long', 3), "\n";
echo str_pad('a', 5, '_', 2), "\n";
echo str_pad('a', 4, '_', 2), "\n";
--EXPECT--
5000
0005
hi   
long
__a__
_a__
