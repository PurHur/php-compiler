<?php

declare(strict_types=1);

/**
 * Issue #6488 — openssl_pbkdf2() PBKDF2 API (ext/openssl/kdf.c).
 */
if (!function_exists('openssl_pbkdf2')) {
    fwrite(STDERR, "fail: openssl_pbkdf2 not registered\n");
    exit(1);
}

$expected = '632c2812e46d4604102ba7618e9d6d7d2f8128f6';
$actual = bin2hex(openssl_pbkdf2('password', 'salt', 20, 1000, 'sha256'));
if ($expected !== $actual) {
    fwrite(STDERR, "fail: digest mismatch: {$actual}\n");
    exit(1);
}

$zendRef = hash_pbkdf2('sha256', 'password', 'salt', 1000, 20, true);
if (openssl_pbkdf2('password', 'salt', 20, 1000, 'sha256') !== $zendRef) {
    fwrite(STDERR, "fail: openssl_pbkdf2 != hash_pbkdf2 raw vector\n");
    exit(1);
}

echo "ok\n";
