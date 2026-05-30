--TEST--
Spaceship operator (<=>) on arrays — Zend zend_compare_arrays parity
--FILE--
<?php
echo [] <=> [], "\n";
echo [1] <=> [1], "\n";
echo [1, 2] <=> [1, 3], "\n";
echo [1, 2] <=> [1, 2, 3], "\n";
echo ['a' => 1, 'b' => 2] <=> ['a' => 1, 'b' => 3], "\n";
--EXPECT--
0
0
-1
-1
-1
