<?php

declare(strict_types=1);

enum E: string
{
    case A = 'x';
}

try {
    spl_autoload_register(E::A);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
