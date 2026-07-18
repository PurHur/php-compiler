--TEST--
stdlib openssl_x509_checkpurpose() — purpose check + X509_PURPOSE_* (#20286, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!PHPCompiler\ext\openssl\VmOpensslX509Native::available()) die('skip no libcrypto FFI');
if (!PHPCompiler\ext\openssl\VmOpensslPkeyNative::available()) die('skip no libcrypto FFI');
?>
--FILE--
<?php
declare(strict_types=1);
var_export(function_exists('openssl_x509_checkpurpose'));
echo "\n";
var_export(defined('X509_PURPOSE_SSL_SERVER'));
echo "\n";
echo X509_PURPOSE_SSL_SERVER, "\n";

$dn = [
    'countryName' => 'US',
    'stateOrProvinceName' => 'CA',
    'localityName' => 'SF',
    'organizationName' => 'TestOrg',
    'commonName' => 'checkpurpose.example',
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

$r = openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_SERVER);
var_export($r);
echo ' ', gettype($r), "\n";

$pem = '';
if (!openssl_x509_export($cert, $pem) || !str_contains($pem, 'BEGIN')) {
    echo "export-fail\n";
    exit(1);
}
$caFile = tempnam(sys_get_temp_dir(), 'cainfo');
file_put_contents($caFile, $pem);
$trusted = openssl_x509_checkpurpose($cert, X509_PURPOSE_SSL_SERVER, [$caFile]);
var_export($trusted);
echo ' ', gettype($trusted), "\n";
$any = openssl_x509_checkpurpose($cert, X509_PURPOSE_ANY, [$caFile]);
var_export($any);
echo "\n";
unlink($caFile);

$bad = openssl_x509_checkpurpose('not-a-cert', X509_PURPOSE_SSL_SERVER);
var_export($bad);
echo ' ', gettype($bad), "\n";

try {
    openssl_x509_checkpurpose([], X509_PURPOSE_SSL_SERVER);
    echo "no-error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
true
2
false boolean
true boolean
true
-1 integer
openssl_x509_checkpurpose(): Argument #1 ($certificate) must be of type OpenSSLCertificate|string, array given
