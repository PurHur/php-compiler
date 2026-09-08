<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 51;
}
function viaPow(int $n): int {
    return pow($n, 51);
}
function overflow(int $n) {
    return $n ** 51;
}
echo work(2), "
";
echo work(-2), "
";
echo work(0), "
";
echo viaPow(2), "
";
echo gettype(overflow(PHP_INT_MAX)), "
";
