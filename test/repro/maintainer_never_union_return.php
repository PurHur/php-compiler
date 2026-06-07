<?php
// Repro for #7414 — PHP 8.2+ allows never in union return/parameter types.
function ok(): int|never {
    throw new Exception('unreachable');
}

function g(int|never $x): int {
    return $x;
}

try {
    ok();
} catch (Exception $e) {
    echo 'ok:', $e->getMessage(), "\n";
}
echo 'g:', g(7), "\n";
