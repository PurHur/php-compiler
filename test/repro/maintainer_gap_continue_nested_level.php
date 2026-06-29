<?php
// #13611 — continue 2 in nested foreach must resume outer loop (Zend parity)
function sum_nested_continue(): int {
    $sum = 0;
    foreach ([1, 2, 3] as $x) {
        foreach ([10, 20, 30, 40] as $y) {
            if ($y === 40) {
                continue 2;
            }
            $sum += $y;
        }
        $sum += $x;
    }
    return $sum;
}

echo sum_nested_continue(), "\n";
