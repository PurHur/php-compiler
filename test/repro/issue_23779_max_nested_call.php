<?php

declare(strict_types=1);

/** Repro for #23779 — AOT max() with boxed call-arg operands. */

function g(int $v): int
{
    return $v * 2;
}

echo max(g(1), g(3)), "\n";
echo max('xy', 'zw'), "\n";
