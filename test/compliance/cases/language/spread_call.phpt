--TEST--
call argument spread into variadic function (VM+JIT, #1361 / #141)
--FILE--
<?php
function sum(...$nums) {
    $total = 0;
    foreach ($nums as $n) {
        $total += $n;
    }
    return $total;
}
$args = [1, 2, 3];
echo sum(...$args), "\n";
echo sum(10, ...[2, 3]), "\n";
--EXPECT--
6
15
