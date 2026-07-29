<?php

declare(strict_types=1);

/**
 * Repro #23962 — uuid withheld on default/reference profile without pecl-uuid.
 *
 * Zend (no pecl-uuid): exit 0. VM before fix: extension_loaded('uuid') true → exit 1.
 */
if (extension_loaded('uuid')) {
    fwrite(STDERR, "FAIL: extension_loaded('uuid') true on reference profile\n");
    exit(1);
}
if (function_exists('uuid_create')) {
    fwrite(STDERR, "FAIL: function_exists('uuid_create') true on reference profile\n");
    exit(1);
}
if (defined('UUID_TYPE_RANDOM')) {
    fwrite(STDERR, "FAIL: UUID_TYPE_RANDOM defined on reference profile\n");
    exit(1);
}

echo "ok\n";
