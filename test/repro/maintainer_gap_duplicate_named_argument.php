<?php

declare(strict_types=1);

function sum(int $a, int $b = 0): int
{
    return $a + $b;
}

try {
    sum(a: 1, a: 2, b: 3);
    echo "fail\n";
    exit(1);
} catch (Error $e) {
    echo 'ok:' . $e->getMessage() . "\n";
    exit(0);
}
