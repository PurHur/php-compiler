--TEST--
AOT: echo of inline spaceship (<=>) (#3671)
--FILE--
<?php
echo (1 <=> 2);
echo "\n";
echo (2 <=> 2);
echo "\n";
echo (3 <=> 2);
echo "\n";
echo "x", (1 <=> 2);
echo "\n";
--EXPECT--
-1
0
1
x-1
