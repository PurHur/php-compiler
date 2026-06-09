<?php

declare(strict_types=1);

enum E: string
{
    case A = 'x';
}

try {
    set_exception_handler(E::A);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
