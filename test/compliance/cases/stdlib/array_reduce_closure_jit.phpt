--TEST--
stdlib array_reduce() closure callback JIT/AOT path (issue #3531)
--FILE--
<?php
echo array_reduce([1, 2, 3], fn($c, $i) => $c + $i, 0), "\n";
echo array_reduce([1, 2, 3], function (int $c, int $i): int {
    return $c + $i;
}, 10), "\n";
--EXPECT--
6
16
