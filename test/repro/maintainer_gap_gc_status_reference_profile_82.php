<?php

declare(strict_types=1);

$s = gc_status();
if (!array_key_exists('runs', $s)) {
    echo "skip — forward profile gc_status schema (#12993)\n";
    exit(0);
}
foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
    if (!array_key_exists($key, gc_status())) {
        file_put_contents('php://stderr', "missing key: {$key}\n");
        exit(1);
    }
}
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    if (array_key_exists($key, gc_status())) {
        file_put_contents('php://stderr', "unexpected PHP 8.4 key on reference profile: {$key}\n");
        exit(1);
    }
}
echo "ok\n";
