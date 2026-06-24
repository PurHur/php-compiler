--TEST--
stdlib array_reduce() with user function callback
--FILE--
<?php
function sum($carry, $item)
{
    return $carry + $item;
}
echo array_reduce(array(1, 2, 3), 'sum'), "\n";
echo array_reduce(array(1, 2, 3), 'sum', 10), "\n";
echo array_reduce(array(), 'sum', 0), "\n";
--EXPECT--
6
16
0
