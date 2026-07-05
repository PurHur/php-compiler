<?php

declare(strict_types=1);

if (!function_exists('openssl_pkey_new')) {
    fwrite(STDERR, "fail: openssl_pkey_new missing\n");
    exit(1);
}
if (!function_exists('openssl_pkey_get_private')) {
    fwrite(STDERR, "fail: openssl_pkey_get_private missing\n");
    exit(1);
}
if (!function_exists('openssl_pkey_export')) {
    fwrite(STDERR, "fail: openssl_pkey_export missing\n");
    exit(1);
}

$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    fwrite(STDERR, "fail: openssl_pkey_new returned false\n");
    exit(1);
}

$pem = '';
if (!openssl_pkey_export($key, $pem)) {
    fwrite(STDERR, "fail: openssl_pkey_export returned false\n");
    exit(1);
}
if (!str_contains($pem, 'BEGIN') || !str_contains($pem, 'PRIVATE KEY')) {
    fwrite(STDERR, "fail: export missing PEM markers\n");
    exit(1);
}

$loaded = openssl_pkey_get_private($pem);
if (false === $loaded) {
    fwrite(STDERR, "fail: openssl_pkey_get_private returned false\n");
    exit(1);
}

$data = 'pkey-lifecycle-probe';
$signature = '';
if (!openssl_sign($data, $signature, $loaded, OPENSSL_ALGO_SHA256)) {
    fwrite(STDERR, "fail: openssl_sign with generated key\n");
    exit(1);
}

echo "ok\n";
