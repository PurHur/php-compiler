<?php
declare(strict_types=1);

if (!function_exists('openssl_spki_new')) {
    fwrite(STDERR, "fail: openssl_spki_new missing\n");
    exit(1);
}
if (!function_exists('openssl_spki_verify')) {
    fwrite(STDERR, "fail: openssl_spki_verify missing\n");
    exit(1);
}
if (!function_exists('openssl_spki_export')) {
    fwrite(STDERR, "fail: openssl_spki_export missing\n");
    exit(1);
}
if (!function_exists('openssl_spki_export_challenge')) {
    fwrite(STDERR, "fail: openssl_spki_export_challenge missing\n");
    exit(1);
}

echo 'openssl_spki_new: ', function_exists('openssl_spki_new') ? 'yes' : 'no', "\n";
echo 'openssl_spki_verify: ', function_exists('openssl_spki_verify') ? 'yes' : 'no', "\n";
echo 'openssl_spki_export: ', function_exists('openssl_spki_export') ? 'yes' : 'no', "\n";
echo 'openssl_spki_export_challenge: ', function_exists('openssl_spki_export_challenge') ? 'yes' : 'no', "\n";

$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    fwrite(STDERR, "fail: openssl_pkey_new returned false\n");
    exit(1);
}

$spki = openssl_spki_new($key, 'challenge-string', OPENSSL_ALGO_SHA256);
if (!is_string($spki) || !str_starts_with($spki, 'SPKAC=')) {
    fwrite(STDERR, 'fail: openssl_spki_new did not return SPKAC= string: '.var_export($spki, true)."\n");
    exit(1);
}

$payload = substr($spki, 6);
if (!openssl_spki_verify($payload)) {
    fwrite(STDERR, "fail: openssl_spki_verify returned false for valid SPKAC payload\n");
    exit(1);
}

$pem = openssl_spki_export($payload);
if (!is_string($pem) || !str_contains($pem, 'BEGIN PUBLIC KEY')) {
    fwrite(STDERR, 'fail: openssl_spki_export did not return PEM public key: '.var_export($pem, true)."\n");
    exit(1);
}

$gotChallenge = openssl_spki_export_challenge($payload);
if ($gotChallenge !== 'challenge-string') {
    fwrite(STDERR, 'fail: openssl_spki_export_challenge mismatch: '.var_export($gotChallenge, true)."\n");
    exit(1);
}

if (openssl_spki_verify('!!!not-valid-base64!!!')) {
    fwrite(STDERR, "fail: openssl_spki_verify should reject malformed SPKAC\n");
    exit(1);
}

echo "ok\n";
