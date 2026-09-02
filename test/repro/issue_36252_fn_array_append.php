<?php

declare(strict_types=1);

// Control for #36252: same loop inside a function (should stay fast).
$n = (int) ($argv[1] ?? 8000);

function run(int $n): int
{
    $a = [];
    for ($i = 0; $i < $n; $i++) {
        $a[] = $i;
    }

    return count($a);
}

echo run($n), "\n";
