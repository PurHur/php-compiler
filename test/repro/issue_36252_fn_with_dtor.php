<?php

declare(strict_types=1);

class D { public function __destruct() {} }

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
