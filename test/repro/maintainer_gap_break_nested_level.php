<?php
// #13611 — break 2 in nested foreach control-flow guard (Zend parity)
function sum_nested_break(): int {
    $sum = 0;
    foreach ([1, 2, 3] as $x) {
        foreach ([10, 20, 30, 40] as $y) {
            if ($y === 40) {
                break 2;
            }
            $sum += $y;
        }
        $sum += $x;
    }
    return $sum;
}

echo sum_nested_break(), "\n";
