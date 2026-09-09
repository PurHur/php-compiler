<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 98;
}
function viaPow(int $n): int {
    return pow($n, 98);
}
function overflow(int $n) {
    return $n ** 98;
}
echo work(0), "
";
echo work(1), "
";
echo work(-1), "
";
echo viaPow(0), "
";
echo gettype(overflow(2)), "
";
echo gettype(overflow(-2)), "
";
echo gettype(overflow(PHP_INT_MAX)), "
";
