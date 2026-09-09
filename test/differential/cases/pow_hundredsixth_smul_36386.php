<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 106;
}
function viaPow(int $n): int {
    return pow($n, 106);
}
function overflow(int $n) {
    return $n ** 106;
}
echo work(0), "\n";
echo work(1), "\n";
echo work(-1), "\n";
echo viaPow(0), "\n";
echo gettype(overflow(2)), "\n";
echo gettype(overflow(-2)), "\n";
echo gettype(overflow(PHP_INT_MAX)), "\n";
