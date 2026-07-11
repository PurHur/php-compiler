--TEST--
continue 2 in nested foreach resumes outer loop (#13611, Zend zend_compile.c)
--FILE--
<?php
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
echo sum_nested_continue();
--EXPECT--
180
