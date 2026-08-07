<?php

declare(strict_types=1);

// #28438 — unparenthesized FCC."x" after |> must match Zend (Error), not silent Ax.

$ok = ("a" |> strtoupper(...)) . "x";
if ('Ax' !== $ok) {
    fwrite(STDERR, "parenthesized expected Ax, got {$ok}\n");
    exit(1);
}

try {
    $bad = "a" |> strtoupper(...) . "x";
    fwrite(STDERR, "expected Error, got {$bad}\n");
    exit(1);
} catch (Error $e) {
    if (!str_contains($e->getMessage(), 'Object of class Closure could not be converted to string')) {
        fwrite(STDERR, "unexpected message: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "ok\n";
