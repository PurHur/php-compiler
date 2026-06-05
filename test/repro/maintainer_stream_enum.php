<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
}

try {
    feof(E::A);
    echo "feof: uncaught\n";
} catch (Throwable $e) {
    echo 'feof: ', $e::class, ': ', $e->getMessage(), "\n";
}
try {
    fflush(E::A);
    echo "fflush: uncaught\n";
} catch (Throwable $e) {
    echo 'fflush: ', $e::class, ': ', $e->getMessage(), "\n";
}
try {
    flock(E::A, LOCK_EX);
    echo "flock: uncaught\n";
} catch (Throwable $e) {
    echo 'flock: ', $e::class, ': ', $e->getMessage(), "\n";
}
try {
    fseek(E::A, 0);
    echo "fseek: uncaught\n";
} catch (Throwable $e) {
    echo 'fseek: ', $e::class, ': ', $e->getMessage(), "\n";
}
