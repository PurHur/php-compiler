<?php

declare(strict_types=1);

enum E: string
{
    case A = 'hello';
}

try {
    stristr(E::A, 'LL');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
