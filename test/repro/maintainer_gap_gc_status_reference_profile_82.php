<?php

declare(strict_types=1);

foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
    if (!array_key_exists($key, gc_status())) {
        fwrite(STDERR, "missing key: {$key}\n");
        exit(1);
    }
}
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    if (array_key_exists($key, gc_status())) {
        fwrite(STDERR, "unexpected PHP 8.4 key on reference profile: {$key}\n");
        exit(1);
    }
}
echo "ok\n";
