<?php
declare(strict_types=1);

$fib = function (int $n) use (&$fib): int {
    return $n <= 1 ? $n : $fib($n - 1) + $fib($n - 2);
};
echo 'fib5=', $fib(5), PHP_EOL;

$g = function (int $i) use (&$g): void {
    if ($i >= 3) {
        return;
    }
    echo $i;
    $g($i + 1);
};
echo 'g=';
$g(0);
echo PHP_EOL;
