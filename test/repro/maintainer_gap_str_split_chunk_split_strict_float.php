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

expectTypeError(static fn () => str_split('abc', 1.9), 'str_split(1.9)');
expectTypeError(static fn () => chunk_split('abc', 1.9), 'chunk_split(1.9)');
expectTypeError(static fn () => wordwrap('hello world', 5.9), 'wordwrap(5.9)');
expectTypeError(static fn () => count_chars('hello', 1.9), 'count_chars(1.9)');
expectTypeError(static fn () => strspn('hello', 'hel', 1.9, 2.7), 'strspn(1.9,2.7)');
expectTypeError(static fn () => strcspn('hello', 'x', 1.9, 2.7), 'strcspn(1.9,2.7)');

exit($fail === 0 ? 0 : 1);
