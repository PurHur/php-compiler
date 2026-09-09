<?php
declare(strict_types=1);
function work(int $n): int {
    return $n ** 60;
}
function viaPow(int $n): int {
    return pow($n, 60);
}
function overflow(int $n) {
    return $n ** 60;
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
