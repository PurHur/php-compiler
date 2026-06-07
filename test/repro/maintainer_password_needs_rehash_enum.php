<?php

declare(strict_types=1);

enum E: string
{
    case A = 'secret';
}

try {
    password_needs_rehash(E::A, PASSWORD_BCRYPT);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
