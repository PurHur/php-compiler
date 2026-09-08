<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 41;
}
function viaPow(int $n): int {
    return pow($n, 41);
}
function overflow(int $n) {
    return $n ** 41;
}
echo work(2), "\n";
echo work(-2), "\n";
echo work(0), "\n";
echo viaPow(2), "\n";
echo gettype(overflow(PHP_INT_MAX)), "\n";
