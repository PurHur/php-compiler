<?php

declare(strict_types=1);

/**
 * Exceptions — throw/catch volume (#36385).
 */

function mayThrow(int $i): int
{
    if (($i & 7) === 0) {
        throw new RuntimeException('x'.$i);
    }

    return $i;
}

$n = 10000;
$ok = 0;
$caught = 0;
for ($i = 0; $i < $n; ++$i) {
    try {
        $ok += mayThrow($i);
    } catch (RuntimeException $e) {
        ++$caught;
        $ok += strlen($e->getMessage());
    }
}

echo $ok, '|', $caught, "\n";
