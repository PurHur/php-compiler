<?php

declare(strict_types=1);

/** Issue #16676 — clone-with on PHP_COMPILER_PROFILE=8.4 forward profile. */

class Src
{
    public int $x = 1;
}

$src = new Src();
$copy = clone $src with { x: 2 };
if (2 !== $copy->x) {
    echo 'fail block ', $copy->x, "\n";
    exit(1);
}

$copy2 = clone ($src, with: ['x' => 3]);
if (3 !== $copy2->x) {
    echo 'fail named ', $copy2->x, "\n";
    exit(1);
}

echo "ok\n";
