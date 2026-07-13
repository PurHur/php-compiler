<?php

declare(strict_types=1);

/**
 * Issue #18594 — openssl_encrypt()/openssl_decrypt() symmetric EVP round-trip.
 *
 * php-src: ext/openssl/openssl.c
 */
$key = '0123456789abcdef';
$iv = '0123456789abcdef';

$raw = openssl_encrypt('data', 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
if (false === $raw) {
    fwrite(STDERR, "fail: openssl_encrypt raw returned false\n");
    exit(1);
}
if ('840a0c413dca6e7dcc58783214795053' !== bin2hex($raw)) {
    fwrite(STDERR, 'fail: raw ciphertext hex mismatch: '.bin2hex($raw)."\n");
    exit(1);
}

$plain = openssl_decrypt($raw, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
if ('data' !== $plain) {
    fwrite(STDERR, "fail: raw decrypt expected data got ".var_export($plain, true)."\n");
    exit(1);
}

$b64 = openssl_encrypt('data', 'AES-128-CBC', $key, 0, $iv);
if ('hAoMQT3Kbn3MWHgyFHlQUw==' !== $b64) {
    fwrite(STDERR, "fail: base64 ciphertext mismatch: {$b64}\n");
    exit(1);
}

$plainB64 = openssl_decrypt($b64, 'AES-128-CBC', $key, 0, $iv);
if ('data' !== $plainB64) {
    fwrite(STDERR, "fail: base64 decrypt expected data got ".var_export($plainB64, true)."\n");
    exit(1);
}

if (false !== openssl_encrypt('data', 'NOPE-CIPHER', $key, 0, $iv)) {
    fwrite(STDERR, "fail: unknown cipher should return false\n");
    exit(1);
}

echo "ok\n";
