--TEST--
stdlib array_reduce() with closure callback (issue #3531)
--FILE--
<?php
echo array_reduce([1, 2, 3], fn($c, $i) => $c + $i, 0), "\n";
echo array_reduce([1, 2, 3], function (int $c, int $i): int {
    return $c + $i;
}, 10), "\n";
echo array_reduce([], fn($c, $i) => $c + $i, 0), "\n";
--EXPECT--
6
16
0
