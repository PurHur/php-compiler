<?php
// #19016 — openssl_encrypt(null) must coerce to '' and return ciphertext (ext/openssl/openssl.c).
// Run with: env -u PHP_COMPILER_PROFILE php bin/vm.php test/repro/maintainer_gap_openssl_encrypt_null_coerce.php

$key = str_repeat('k', 32);
$iv = str_repeat('i', 16);

$empty = @openssl_encrypt('', 'aes-256-cbc', $key, 0, $iv);
$null = @openssl_encrypt(null, 'aes-256-cbc', $key, 0, $iv);

if (false === $empty || false === $null) {
    fwrite(STDERR, "encrypt failed empty=" . var_export($empty, true) . " null=" . var_export($null, true) . "\n");
    exit(1);
}

if ($empty !== $null) {
    fwrite(STDERR, "mismatch empty={$empty} null={$null}\n");
    exit(1);
}

echo "ok\n";
