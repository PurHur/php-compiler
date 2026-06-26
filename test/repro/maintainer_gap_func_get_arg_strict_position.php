<?php

declare(strict_types=1);

function f($a, $b): void
{
    try {
        func_get_arg('0');
        fwrite(STDERR, "expected TypeError for string position under strict_types\n");
        exit(1);
    } catch (TypeError $e) {
        if (!str_contains($e->getMessage(), 'func_get_arg(): Argument #1 ($position) must be of type int, string given')) {
            fwrite(STDERR, 'unexpected message: '.$e->getMessage()."\n");
            exit(1);
        }
    }
    echo func_get_arg(0), "\n";
}

f(10, 20);

echo "ok\n";
