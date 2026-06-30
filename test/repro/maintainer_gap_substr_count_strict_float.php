<?php

declare(strict_types=1);

$fail = 0;

function expectTypeError(callable $fn, string $label): void
{
    global $fail;
    try {
        $fn();
        echo "FAIL $label: expected TypeError\n";
        ++$fail;
    } catch (TypeError) {
        // ok
    }
}

expectTypeError(static fn () => substr_count('abcabc', 'a', 1.9), 'substr_count offset 1.9');
expectTypeError(static fn () => substr_count('abcabc', 'a', 0, 2.9), 'substr_count length 2.9');

exit($fail === 0 ? 0 : 1);
