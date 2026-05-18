--TEST--
stdlib str_pad()
--FILE--
<?php
echo str_pad('5', 4, '0'), "\n";
echo str_pad('5', 4, '0', 1), "\n";
echo str_pad('hi', 5), "\n";
echo str_pad('long', 3), "\n";
--EXPECT--
5000
0005
hi   
long
