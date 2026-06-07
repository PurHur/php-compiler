<?php

declare(strict_types=1);

enum E: string
{
    case A = 'kitten';
}

try {
    levenshtein(E::A, 'sitting');
    fwrite(STDERR, "FAIL: expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    $expected = 'levenshtein(): Argument #1 ($string1) must be of type string, E given';
    if ($e->getMessage() !== $expected) {
        fwrite(STDERR, "FAIL: unexpected message: {$e->getMessage()}\n");
        exit(1);
    }
    echo "OK\n";
}
