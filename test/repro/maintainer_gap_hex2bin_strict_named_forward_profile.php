<?php

declare(strict_types=1);

/**
 * Repro #27763 — named $strict rejected on forward profile (php-src arity 1).
 */
try {
    hex2bin('zz', strict: true);
    echo "FAIL: expected unknown named parameter\n";
    exit(1);
} catch (\Error $e) {
    echo "ok:{$e->getMessage()}\n";
}
