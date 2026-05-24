--TEST--
variadic function sums trailing arguments (VM, issue #197)
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
function log_level(string $level, ...$messages) {
    echo $level, ':', count($messages), "\n";
}
log_level('info', 'a', 'b');
--EXPECT--
6
0
info:2
