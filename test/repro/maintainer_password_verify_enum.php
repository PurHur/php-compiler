<?php

declare(strict_types=1);

enum E: string
{
    case A = 'secret';
}

try {
    var_dump(password_verify(E::A, '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy'));
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
