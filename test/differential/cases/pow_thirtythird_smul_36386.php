<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 33;
}
function viaPow(int $n): int {
    return pow($n, 33);
}
function overflow(int $n) {
    return $n ** 33;
}
echo work(3), "\n";
echo work(-2), "\n";
echo work(0), "\n";
echo viaPow(2), "\n";
echo gettype(overflow(PHP_INT_MAX)), "\n";
