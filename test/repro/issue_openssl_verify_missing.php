<?php

declare(strict_types=1);

/**
 * Issue #11535 — openssl_verify() registration + sign/verify round-trip.
 *
 * php-src: ext/openssl/openssl.c
 */

if (!function_exists('openssl_sign') || !function_exists('openssl_verify')) {
    echo "fail: openssl_sign/verify not registered\n";
    exit(1);
}

// 512-bit RSA test key pair (fixture only — not for production).
$privateKey = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIBVAIBADANBgkqhkiG9w0BAQEFAASCAT4wggE6AgEAAkEAs/agkMDOJDS7Udfu
b2zoYYZdjXjmjEGVAKQ0jcNsjzx8UizZZdezyq9Cb/a1Z8epPFm0KPXWO/DrfaO/
pJdN0wIDAQABAkEAqAYbsisiDLHjNy35o7U2Xl/6lu0LrGZK/TdTDg0pHa2Tg2bU
sRDsUL7mG+Sg7nXUkGQnMOc6PjHwRlF1v5i6EQIhAO6cRDOKu4OzmpsFpDz8RcAb
fKcHtRGQoqNiHGkjOrd7AiEAwRQwNwDjClD+3IMkLHR/1d2MSRunQ/mYf+SHs51Y
R4kCIA4uXWNO0HwwVXT3Ld6uA5s6RvtKWvmTRgc90oBxJpE3AiAXGnVSf5arS1nT
xRV1BFOvoZ0Bun9fUOSAmTXrti40EQIgd7h1Ch05DM18TUSosFD/valTgZyBNqO5
YQqYKeRM/Yk=
-----END PRIVATE KEY-----
PEM;

$publicKey = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBALP2oJDAziQ0u1HX7m9s6GGGXY145oxB
lQCkNI3DbI88fFIs2WXXs8qvQm/2tWfHqTxZtCj11jvw632jv6SXTdMCAwEAAQ==
-----END PUBLIC KEY-----
PEM;

$data = 'php-compiler openssl verify probe';
$signature = '';
if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    echo 'fail: openssl_sign returned false'."\n";
    exit(1);
}

$result = openssl_verify($data, $signature, $publicKey, OPENSSL_ALGO_SHA256);
if (1 !== $result) {
    echo 'fail: openssl_verify expected 1 got '.$result."\n";
    exit(1);
}

$bad = openssl_verify('tampered', $signature, $publicKey, OPENSSL_ALGO_SHA256);
if (0 !== $bad) {
    echo 'fail: tampered verify expected 0 got '.$bad."\n";
    exit(1);
}

echo "ok\n";
