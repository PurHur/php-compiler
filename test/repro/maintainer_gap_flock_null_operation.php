<?php

declare(strict_types=1);

/**
 * Maintainer repro: flock() null $operation must ValueError (#16575, ext/standard/flock.c).
 */

$fp = fopen('php://memory', 'r+');
if (false === $fp) {
    fwrite(STDERR, "fopen failed\n");
    exit(1);
}

try {
    flock($fp, null);
    echo "fail: uncaught\n";
    exit(1);
} catch (ValueError $e) {
    if ('flock(): Argument #2 ($operation) must be one of LOCK_SH, LOCK_EX, or LOCK_UN' !== $e->getMessage()) {
        echo 'fail: message: ', $e->getMessage(), "\n";
        exit(1);
    }
    echo "ok\n";
}
