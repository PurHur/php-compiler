<?php
// Repro #20286 — openssl_x509_checkpurpose registration + self-signed / trusted CA
declare(strict_types=1);

if (!function_exists('openssl_x509_checkpurpose')) {
    fwrite(STDERR, "missing openssl_x509_checkpurpose\n");
    exit(1);
}
if (!defined('X509_PURPOSE_SSL_SERVER')) {
    fwrite(STDERR, "missing X509_PURPOSE_SSL_SERVER\n");
    exit(1);
}

$dn = ['commonName' => 'repro-20286'];
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], 1);

$untrusted = openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_SERVER);
if (false !== $untrusted) {
    fwrite(STDERR, "expected self-signed without CA to be false, got ".var_export($untrusted, true)."\n");
    exit(1);
}

$pem = '';
openssl_x509_export($cert, $pem);
$caFile = tempnam(sys_get_temp_dir(), 'repro20286');
file_put_contents($caFile, $pem);
$trusted = openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_SERVER, [$caFile]);
unlink($caFile);
if (true !== $trusted) {
    fwrite(STDERR, "expected trusted CA purpose check true, got ".var_export($trusted, true)."\n");
    exit(1);
}

$missing = openssl_x509_checkpurpose('not-a-cert', X509_PURPOSE_SSL_SERVER);
if (-1 !== $missing) {
    fwrite(STDERR, "expected -1 for bad cert, got ".var_export($missing, true)."\n");
    exit(1);
}

echo "ok\n";
