<?php
// Part of #36386 — leaf-recursive typed fibo must match Zend after no-throw call elision.
declare(strict_types=1);

function fibo_r(int $n): int
{
    return ($n < 2) ? 1 : fibo_r($n - 2) + fibo_r($n - 1);
}

echo fibo_r(12), "\n";
