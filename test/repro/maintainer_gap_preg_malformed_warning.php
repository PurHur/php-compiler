<?php

declare(strict_types=1);

function assert_warning(string $function, callable $call): void
{
    $call();
    $last = error_get_last();
    if (null === $last) {
        fwrite(STDERR, "{$function}: expected E_WARNING, error_get_last() is null\n");
        exit(1);
    }
    if (!str_contains($last['message'], $function.'():')) {
        fwrite(STDERR, "{$function}: unexpected message: {$last['message']}\n");
        exit(1);
    }
    if (!str_contains($last['message'], 'No ending delimiter')) {
        fwrite(STDERR, "{$function}: expected delimiter warning, got: {$last['message']}\n");
        exit(1);
    }
}

assert_warning('preg_match', static fn () => preg_match('/[', 'x'));
assert_warning('preg_replace', static fn () => preg_replace('/[', 'y', 'x'));
assert_warning('preg_split', static fn () => preg_split('/[', 'x'));
assert_warning('preg_grep', static fn () => preg_grep('/[', ['x']));

echo "ok\n";
