<?php

declare(strict_types=1);

try {
    sizeof('x');
    fwrite(STDERR, "expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_starts_with($e->getMessage(), 'sizeof(): Argument #1')) {
        fwrite(STDERR, 'wrong message: '.$e->getMessage()."\n");
        exit(1);
    }
}

try {
    count('x');
    fwrite(STDERR, "expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_starts_with($e->getMessage(), 'count(): Argument #1')) {
        fwrite(STDERR, 'wrong message: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok\n";
