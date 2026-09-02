<?php

declare(strict_types=1);

// #36252 control — function-scoped append must match {main} cost.
$n = (int) ($argv[1] ?? 16000);

function run(int $n): int
{
    $a = [];
    for ($i = 0; $i < $n; $i++) {
        $a[] = $i;
    }

    return count($a);
}

echo run($n), "\n";
