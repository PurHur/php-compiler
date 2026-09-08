<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 24;
}
function viaPow(int $n): int {
    return pow($n, 24);
}
function overflow(int $n) {
    return $n ** 24;
}
echo work(3), "\n";
echo work(-2), "\n";
echo work(0), "\n";
echo viaPow(2), "\n";
echo gettype(overflow(PHP_INT_MAX)), "\n";
