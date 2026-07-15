--TEST--
openssl_csr_new/export/sign/get_* lifecycle (#6421, ext/openssl/xp.c)
--FILE--
<?php
if (!function_exists('openssl_csr_new')) {
    echo "missing\n";
    exit(1);
}
$dn = [
    'countryName' => 'US',
    'stateOrProvinceName' => 'CA',
    'localityName' => 'SF',
    'organizationName' => 'TestOrg',
    'commonName' => 'example.com',
];
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "key-fail\n";
    exit(1);
}
$csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
if (false === $csr) {
    echo "csr-fail\n";
    exit(1);
}
if (!($csr instanceof OpenSSLCertificateSigningRequest)) {
    echo "csr-type-fail\n";
    exit(1);
}
$pem = '';
if (!openssl_csr_export($csr, $pem) || !str_contains($pem, 'BEGIN CERTIFICATE REQUEST')) {
    echo "export-fail\n";
    exit(1);
}
$tmp = tempnam(sys_get_temp_dir(), 'phpc-csr-');
if (!openssl_csr_export_to_file($csr, $tmp) || !is_file($tmp) || filesize($tmp) < 32) {
    echo "tofile-fail\n";
    @unlink($tmp);
    exit(1);
}
@unlink($tmp);
$subj = openssl_csr_get_subject($csr);
if (!is_array($subj) || ($subj['CN'] ?? null) !== 'example.com' || ($subj['C'] ?? null) !== 'US') {
    echo "subject-fail\n";
    exit(1);
}
$pub = openssl_csr_get_public_key($csr);
if (!($pub instanceof OpenSSLAsymmetricKey)) {
    echo "pubkey-fail\n";
    exit(1);
}
$cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], 1);
if (!($cert instanceof OpenSSLCertificate)) {
    echo "sign-fail\n";
    exit(1);
}
$parsed = openssl_x509_parse($cert);
if (!is_array($parsed) || ($parsed['subject']['CN'] ?? null) !== 'example.com') {
    echo "parse-fail\n";
    exit(1);
}
echo "ok\n";
?>
--EXPECT--
ok
