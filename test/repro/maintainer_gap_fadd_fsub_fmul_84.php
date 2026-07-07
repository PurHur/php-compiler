<?php

declare(strict_types=1);

/**
 * Issue #17290 — fadd()/fsub()/fmul() on PHP_COMPILER_PROFILE=8.4.
 */

$required = ['fadd', 'fsub', 'fmul'];
$missing = array_values(array_filter($required, static fn (string $fn): bool => !function_exists($fn)));
if ([] !== $missing) {
    fwrite(STDERR, 'fail: missing '.implode(',', $missing)."\n");
    exit(1);
}

$add = fadd(0.1, 0.2);
$sub = fsub(1.0, 0.3);
$mul = fmul(2.0, 3.0);
if (!is_float($add) || !is_float($sub) || !is_float($mul)) {
    fwrite(STDERR, "fail: non-float results\n");
    exit(1);
}

echo 'ok add=', $add, ' sub=', $sub, ' mul=', $mul, "\n";
