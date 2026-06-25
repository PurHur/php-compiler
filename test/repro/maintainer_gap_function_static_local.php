<?php

declare(strict_types=1);

/**
 * Issue #11451 — function static locals must persist when invoked via higher-order callback.
 */

function counter(): int
{
    static $c = 0;

    return ++$c;
}

function invoke(callable $fn): int
{
    return $fn();
}

$a = invoke(fn () => counter());
$b = invoke(fn () => counter());

if (1 === $a && 2 === $b) {
    echo "static_via_hof_ok\n";
    exit(0);
}

echo "static_via_hof_fail\n";
exit(1);
