<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    phpversion(E::A);
    echo "fail: uncaught\n";
    exit(1);
} catch (TypeError $e) {
    $expected = 'phpversion(): Argument #1 ($extension) must be of type ?string, E given';
    if ($expected !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
}

echo "ok\n";
