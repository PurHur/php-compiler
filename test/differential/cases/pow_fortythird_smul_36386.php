<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 43;
}
function viaPow(int $n): int {
    return pow($n, 43);
}
function overflow(int $n) {
    return $n ** 43;
}
echo work(2), "\n";
echo work(-2), "\n";
echo work(0), "\n";
echo viaPow(2), "\n";
echo gettype(overflow(PHP_INT_MAX)), "\n";
