<?php

declare(strict_types=1);

/**
 * Repro #27763 — hex2bin has no $strict on any PROFILE (php-src arity 1).
 */
try {
    hex2bin('zz', strict: true);
    echo "FAIL: expected unknown named / ArgumentCountError\n";
    exit(1);
} catch (\ArgumentCountError $e) {
    echo "ok:{$e->getMessage()}\n";
} catch (\Error $e) {
    echo "ok:{$e->getMessage()}\n";
}
