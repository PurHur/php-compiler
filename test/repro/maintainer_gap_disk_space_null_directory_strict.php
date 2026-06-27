<?php

declare(strict_types=1);

// Repro for #12619 — disk_*_space(null) under strict_types must TypeError (php-src filestat.c).
try {
    disk_free_space(null);
    echo "fail: disk_free_space(null) uncaught\n";
    exit(1);
} catch (TypeError $e) {
    if ('disk_free_space(): Argument #1 ($directory) must be of type string, null given' !== $e->getMessage()) {
        echo 'fail: ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok\n";
    exit(0);
}
