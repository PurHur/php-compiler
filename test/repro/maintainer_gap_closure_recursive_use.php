<?php

declare(strict_types=1);

$fib = function (int $n) use (&$fib) {
    return $n <= 1 ? $n : $fib($n - 1) + $fib($n - 2);
};

echo $fib(5), "\n";

$g = function (int $i) use (&$g) {
    if ($i >= 3) {
        return;
    }
    echo $i;
    $g($i + 1);
};
$g(0);
echo "\n";
