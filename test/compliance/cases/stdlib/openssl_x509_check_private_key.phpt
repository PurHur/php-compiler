--TEST--
stdlib openssl_x509_check_private_key() — matching / mismatched keys (#20285, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!PHPCompiler\ext\openssl\VmOpensslX509Native::available()) die('skip no libcrypto FFI');
if (!PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) die('skip no libcrypto FFI');
?>
--FILE--
<?php
declare(strict_types=1);
var_export(function_exists('openssl_x509_check_private_key'));
echo "\n";

$dn = [
    'countryName' => 'US',
    'stateOrProvinceName' => 'CA',
    'localityName' => 'SF',
    'organizationName' => 'TestOrg',
    'commonName' => 'check-private-key.example',
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
$cert = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256'], 1);
if (false === $cert) {
    echo "sign-fail\n";
    exit(1);
}

$pem = '';
if (!openssl_pkey_export($key, $pem) || !str_contains($pem, 'BEGIN')) {
    echo "export-fail\n";
    exit(1);
}

var_export(openssl_x509_check_private_key($cert, $pem));
echo "\n";
var_export(openssl_x509_check_private_key($cert, $key));
echo "\n";

$other = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$otherPem = '';
openssl_pkey_export($other, $otherPem);
var_export(openssl_x509_check_private_key($cert, $otherPem));
echo "\n";

var_export(openssl_x509_check_private_key('not-a-cert', $pem));
echo "\n";

try {
    openssl_x509_check_private_key([], $pem);
    echo "no-error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
true
false
false
openssl_x509_check_private_key(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, array given
