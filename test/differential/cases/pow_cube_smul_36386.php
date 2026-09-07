<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 3;
}
function viaPow(int $n): int {
    return pow($n, 3);
}
function overflow(int $n) {
    return $n ** 3;
}
echo work(5), "\n";
echo work(-4), "\n";
echo work(0), "\n";
echo viaPow(6), "\n";
echo gettype(overflow(PHP_INT_MAX)), "\n";
