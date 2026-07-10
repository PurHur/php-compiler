<?php

declare(strict_types=1);

$h = password_hash('x', PASSWORD_BCRYPT);

try {
    password_needs_rehash($h, 3.14);
    fwrite(STDERR, "fail: expected TypeError for float algo\n");
    exit(1);
} catch (\TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type string|int')) {
        fwrite(STDERR, "fail: unexpected message: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "ok\n";
