--TEST--
variadic function sums trailing arguments (JIT, issue #197)
--JIT--
--FILE--
<?php
function sum(...$nums) {
    $total = 0;
    foreach ($nums as $n) {
        $total += $n;
    }
    return $total;
}
echo sum(1, 2, 3), "\n";
echo sum(), "\n";
--EXPECT--
6
0
