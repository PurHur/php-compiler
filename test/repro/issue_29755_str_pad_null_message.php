<?php

/**
 * #29755 — str_pad(..., null) $pad_string ValueError must match Zend 8.2:
 * "must be a non-empty string" (php-src ext/standard/string.c).
 * PROFILE≥8.4 keeps "must not be empty" (#29292).
 *
 * No declare(strict_types=1) — soft-null DEP+coerce matches Zend php-src-strict.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    // Soft-null DEP is expected; swallow so the ValueError message is clear.
    return true;
});

$expected = 'str_pad(): Argument #3 ($pad_string) must be a non-empty string';

try {
    str_pad('x', 5, null);
    fwrite(STDERR, "fail: str_pad(..., null) expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'null:', $e->getMessage(), "\n";
}

try {
    str_pad('x', 5, '');
    fwrite(STDERR, "fail: str_pad(..., '') expected ValueError\n");
    exit(1);
} catch (ValueError $e) {
    if ($expected !== $e->getMessage()) {
        fwrite(STDERR, "fail: got {$e->getMessage()}\n");
        exit(1);
    }
    echo 'empty:', $e->getMessage(), "\n";
}

echo "ok\n";
