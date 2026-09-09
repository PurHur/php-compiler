<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 59;
}
function viaPow(int $n): int {
    return pow($n, 59);
}
function overflow(int $n) {
    return $n ** 59;
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
