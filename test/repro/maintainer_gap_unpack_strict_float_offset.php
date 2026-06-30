<?php

declare(strict_types=1);

try {
    unpack('c', "\x01", 1.9);
    fwrite(STDERR, "fail: expected TypeError for float offset under strict_types\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'unpack(): Argument #3 ($offset) must be of type int, float given')) {
        fwrite(STDERR, 'unexpected message: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
