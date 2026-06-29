<?php

declare(strict_types=1);

try {
    explode('-', 'a-b-c-d', 2.9);
    fwrite(STDERR, "fail: expected TypeError for float limit under strict_types\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'explode(): Argument #3 ($limit) must be of type int, float given')) {
        fwrite(STDERR, 'unexpected message: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
