<?php
// #19016 — openssl_encrypt(null) must coerce to '' and return ciphertext (ext/openssl/openssl.c).
// Run with: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_openssl_encrypt_null_coerce.php

declare(strict_types=1);

if (!function_exists('openssl_encrypt')) {
    fwrite(STDERR, "skip: openssl_encrypt unavailable\n");
    exit(0);
}

$key = str_repeat('k', 32);
$iv = str_repeat('i', 16);

$empty = @openssl_encrypt('', 'aes-256-cbc', $key, 0, $iv);
$null = @openssl_encrypt(null, 'aes-256-cbc', $key, 0, $iv);

if (false === $empty || false === $null) {
    fwrite(STDERR, "fail: expected ciphertext for empty/null data\n");
    exit(1);
}

if ($empty !== $null) {
    fwrite(STDERR, "fail: null ciphertext must match empty-string ciphertext\n");
    fwrite(STDERR, "empty={$empty}\n");
    fwrite(STDERR, "null={$null}\n");
    exit(1);
}

echo "ok\n";
