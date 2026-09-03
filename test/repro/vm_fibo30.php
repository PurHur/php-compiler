<?php

declare(strict_types=1);

/**
 * Done-when probe for #36411 / #36449: fibo(30) under bin/vm.php must finish in < 60s.
 * Matches benchmarks/fibo(30).php (ternary self-recursive typed int).
 */
function fibo_r(int $n): int
{
    return ($n < 2) ? 1 : fibo_r($n - 2) + fibo_r($n - 1);
}

function fibo(int $n): void
{
    $r = fibo_r($n);
    echo $r;
    echo "\n";
}

fibo((int) ($argv[1] ?? 30));
