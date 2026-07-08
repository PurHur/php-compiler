<?php

declare(strict_types=1);

/**
 * Maintainer repro: typed function-local static on PHP_COMPILER_PROFILE=8.4 (#17381).
 */

if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== '8.3') {
    fwrite(STDERR, "skip: requires PHP_COMPILER_PROFILE=8.3 or 8.4\n");
    exit(0);
}

function counter(): int
{
    static int $n = 0;

    return ++$n;
}

$first = counter();
$second = counter();
if (1 !== $first || 2 !== $second) {
    echo "fail: expected 1,2 got {$first},{$second}\n";
    exit(1);
}

function rejectBadAssign(): void
{
    static string $s = 'ok';
    $s = 1;
}

try {
    rejectBadAssign();
    echo "fail: expected TypeError on bad assign\n";
    exit(1);
} catch (TypeError) {
    // expected
}

echo "ok\n";
