<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 42;
}
function viaPow(int $n): int {
    return pow($n, 42);
}
function overflow(int $n) {
    return $n ** 42;
}
echo work(2), "\n";
echo work(-2), "\n";
echo work(0), "\n";
echo viaPow(2), "\n";
echo gettype(overflow(PHP_INT_MAX)), "\n";
