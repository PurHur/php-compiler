<?php

declare(strict_types=1);

// Maintainer repro for #16675 — pipe operator under PHP_COMPILER_PROFILE=8.4 forward profile.
$x = 5 |> fn ($v) => $v * 2;
if (10 !== $x) {
    fwrite(STDERR, "fail: expected 10, got {$x}\n");
    exit(1);
}

echo "ok\n";
