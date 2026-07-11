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
    $message = $last['message'];
    if (!\is_string($message)) {
        fwrite(STDERR, "{$function}: message type is " . get_debug_type($message) . " not string\n");
        exit(1);
    }
    if (!str_contains($message, $function . '():')) {
        fwrite(STDERR, "{$function}: unexpected message: {$message}\n");
        exit(1);
    }
}

assert_warning('preg_match', static fn () => preg_match('/[', 'x'));

echo "ok\n";
