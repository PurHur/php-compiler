<?php
// Repro #20285 — openssl_x509_check_private_key matching / mismatched
declare(strict_types=1);

if (!function_exists('openssl_x509_check_private_key')) {
    fwrite(STDERR, "missing openssl_x509_check_private_key\n");
    exit(1);
}

$dn = ['commonName' => 'repro-20285'];
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], 1);
$pem = '';
openssl_pkey_export($key, $pem);

if (!openssl_x509_check_private_key($cert, $pem)) {
    fwrite(STDERR, "expected match true\n");
    exit(1);
}

$other = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$otherPem = '';
openssl_pkey_export($other, $otherPem);
if (openssl_x509_check_private_key($cert, $otherPem)) {
    fwrite(STDERR, "expected mismatch false\n");
    exit(1);
}

echo "ok\n";
