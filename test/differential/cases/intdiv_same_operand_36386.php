<?php
declare(strict_types=1);
function work(int $n): int {
    return intdiv($n, $n);
}
echo work(7), "\n";
echo work(PHP_INT_MIN), "\n";
echo work(-3), "\n";
