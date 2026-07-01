<?php
declare(strict_types=1);

/** Issue #14505 — array_find family must not exist on PHP 8.2 reference profile. */

$funcs = ['array_find', 'array_find_key'];
$bad = array_filter($funcs, static fn (string $fn): bool => function_exists($fn));
if ([] !== $bad) {
    fwrite(STDERR, 'fail: '.implode(', ', $bad)." exposed on 8.2 profile\n");
    exit(1);
}

try {
    array_find([1, 2, 3], static fn (int $v): bool => 2 === $v);
    fwrite(STDERR, "fail: array_find callable on 8.2 profile\n");
    exit(1);
} catch (\Error $e) {
    if (!str_contains($e->getMessage(), 'array_find')) {
        fwrite(STDERR, 'fail: unexpected error: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok_no_funcs\n";
