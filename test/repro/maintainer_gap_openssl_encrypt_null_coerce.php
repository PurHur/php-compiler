<?php
// #19038 — openssl_encrypt(null) TypeError under strict_types (ext/openssl/openssl.c).
// Run with: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_openssl_encrypt_null_coerce.php

declare(strict_types=1);

if (!function_exists('openssl_encrypt')) {
    fwrite(STDERR, "skip: openssl_encrypt unavailable\n");
    exit(0);
}

$key = str_repeat('k', 32);
$iv = str_repeat('i', 16);

try {
    openssl_encrypt(null, 'aes-256-cbc', $key, 0, $iv);
    fwrite(STDERR, "fail: expected TypeError for null data under strict_types\n");
    exit(1);
} catch (TypeError $e) {
    if ($e->getMessage() !== 'openssl_encrypt(): Argument #1 ($data) must be of type string, null given') {
        fwrite(STDERR, "fail: unexpected message: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "ok\n";
