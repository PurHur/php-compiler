<?php
declare(strict_types=1);

function fibo_r(int $n): int
{
    return ($n < 2) ? 1 : fibo_r($n - 2) + fibo_r($n - 1);
}

echo fibo_r(12), "\n";
