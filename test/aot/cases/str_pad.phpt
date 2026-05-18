--TEST--
AOT: str_pad()
--FILE--
<?php
echo str_pad('5', 4, '0'), "\n";
echo str_pad('5', 4, '0', 1), "\n";
--EXPECT--
5000
0005
