--TEST--
variadic function sums trailing arguments (VM)
--FILE--
<?php
function sum(...$nums) {
    return array_sum($nums);
}
echo sum(1, 2, 3), "\n";
echo sum(), "\n";
function log_level($level, ...$messages) {
    echo $level, ':', count($messages), "\n";
}
log_level('info', 'a', 'b');
--EXPECT--
6
0
info:2
