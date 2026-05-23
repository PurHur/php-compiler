--TEST--
stdlib array_reduce() with pow callback
--FILE--
<?php
echo array_reduce([2, 3], 'pow'), "\n";
echo array_reduce([1, 2, 3], 'pow', 10), "\n";
echo array_reduce([], 'pow') === null ? 'null' : 'other', "\n";
echo array_reduce([], 'pow', 0), "\n";
--EXPECT--
8
1000000
null
0
