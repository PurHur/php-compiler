--TEST--
echo of inline spaceship (<=>) — Zend zend_execute.c ZEND_ECHO parity (#3671)
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
$x = 1 <=> 2;
var_dump($x);
echo $x;
echo "\n";
--EXPECT--
-1
0
1
x-1
int(-1)
-1
