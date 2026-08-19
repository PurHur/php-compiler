--TEST--
AOT: packed-array ordered compare / spaceship match Zend zend_compare_arrays (#32501 leftover of #5295)
--FILE--
<?php
echo [1] < [2] ? 't' : 'f', "\n";
echo [1] <=> [2], "\n";
echo [2] > [1] ? 't' : 'f', "\n";
echo [1, 2] <=> [1, 3], "\n";
echo [1, 2] <=> [1, 2, 3], "\n";
echo [] < [0] ? 't' : 'f', "\n";
echo [1] <= [1] ? 't' : 'f', "\n";
echo [2] >= [1] ? 't' : 'f', "\n";
--EXPECT--
t
-1
t
-1
-1
t
t
t
--EXPECT_EXIT--
0
