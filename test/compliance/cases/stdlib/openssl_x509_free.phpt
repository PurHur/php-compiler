--TEST--
openssl_x509_free() deprecated noop (#20272, ext/openssl/openssl.c)
--FILE--
<?php
echo (int) function_exists('openssl_x509_free'), "\n";
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if (false === $key) {
    echo "gen-fail\n";
    exit(1);
}
$csr = openssl_csr_new(['commonName' => 'x509-free.example'], $key, ['digest_alg' => 'sha256']);
if (false === $csr) {
    echo "csr-fail\n";
    exit(1);
}
$cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], 1);
if (!($cert instanceof OpenSSLCertificate)) {
    echo "sign-fail\n";
    exit(1);
}
$prev = error_reporting(E_ALL);
try {
    $r = @openssl_x509_free($cert);
    var_export($r);
    echo "\n";
} finally {
    error_reporting($prev);
}
try {
    openssl_x509_free(null);
    echo "null-ok\n";
} catch (TypeError $e) {
    echo "null-type\n";
}
try {
    openssl_x509_free();
    echo "argc-ok\n";
} catch (ArgumentCountError $e) {
    echo "argc\n";
}
?>
--EXPECT--
1
NULL
null-type
argc
