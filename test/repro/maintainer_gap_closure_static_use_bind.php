<?php

declare(strict_types=1);

function counter(): Closure
{
    static $n = 0;

    return function () use (&$n): int {
        ++$n;

        return $n;
    };
}

$warnings = 0;
set_error_handler(static function () use (&$warnings): bool {
    ++$warnings;

    return true;
});

$c = counter();
$first = $c();
$second = $c();
restore_error_handler();

if (1 !== $first || 2 !== $second || $warnings > 0) {
    exit(1);
}
echo "ok\n";
