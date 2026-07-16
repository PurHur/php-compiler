--TEST--
openssl_cms_sign/verify/encrypt/decrypt/read CMS round-trip (#6592, ext/openssl/openssl.c)
--FILE--
<?php
foreach (['openssl_cms_sign', 'openssl_cms_verify', 'openssl_cms_encrypt', 'openssl_cms_decrypt', 'openssl_cms_read'] as $fn) {
    if (!function_exists($fn)) {
        echo "missing:{$fn}\n";
        exit(1);
    }
}
foreach (['OPENSSL_CMS_BINARY', 'OPENSSL_CMS_NOVERIFY', 'OPENSSL_ENCODING_SMIME', 'OPENSSL_ENCODING_PEM', 'OPENSSL_CIPHER_AES_128_CBC'] as $c) {
    if (!defined($c)) {
        echo "missing-const:{$c}\n";
        exit(1);
    }
}
$certPath = __DIR__ . '/test/fixtures/openssl/pkcs7_test_cert.pem';
$keyPath = __DIR__ . '/test/fixtures/openssl/pkcs7_test_key.pem';
if (!is_file($certPath)) {
    $certPath = dirname(__DIR__, 3) . '/fixtures/openssl/pkcs7_test_cert.pem';
    $keyPath = dirname(__DIR__, 3) . '/fixtures/openssl/pkcs7_test_key.pem';
}
$cert = file_get_contents($certPath);
$key = file_get_contents($keyPath);
if (!is_string($cert) || !is_string($key) || $cert === '' || $key === '') {
    echo "fixture-missing\n";
    exit(1);
}
$tmpdir = sys_get_temp_dir() . '/phpc_cms_' . getmypid();
@mkdir($tmpdir);
$msg = $tmpdir . '/msg.txt';
$signed = $tmpdir . '/signed.cms';
$verified = $tmpdir . '/verified.txt';
$signedPem = $tmpdir . '/signed.pem';
$enc = $tmpdir . '/enc.cms';
$dec = $tmpdir . '/dec.txt';
file_put_contents($msg, "hello cms\n");

$flagsBinary = OPENSSL_CMS_BINARY;
$flagsNoVerify = OPENSSL_CMS_NOVERIFY;
$cipher = OPENSSL_CIPHER_AES_128_CBC;

if (!openssl_cms_sign($msg, $signed, $cert, $key, [], $flagsBinary)) {
    echo "sign-fail\n";
    exit(1);
}
$vr = openssl_cms_verify($signed, $flagsNoVerify, null, [], null, $verified);
if (true !== $vr) {
    echo "verify-fail:" . var_export($vr, true) . "\n";
    exit(1);
}
echo file_get_contents($verified) === "hello cms\n" ? "sign-verify-ok\n" : "sign-verify-mismatch\n";

if (!openssl_cms_encrypt($msg, $enc, $cert, [], $flagsBinary)) {
    echo "encrypt-fail\n";
    exit(1);
}
if (!openssl_cms_decrypt($enc, $dec, $cert, $key)) {
    echo "decrypt-fail\n";
    exit(1);
}
echo file_get_contents($dec) === "hello cms\n" ? "encrypt-decrypt-ok\n" : "encrypt-decrypt-mismatch\n";

if (!openssl_cms_sign($msg, $signedPem, $cert, $key, [], $flagsBinary, OPENSSL_ENCODING_PEM)) {
    echo "sign-pem-fail\n";
    exit(1);
}
$certs = [];
$pem = file_get_contents($signedPem);
if (!openssl_cms_read($pem, $certs) || !is_array($certs) || count($certs) < 1) {
    echo "read-fail\n";
    exit(1);
}
echo str_contains($certs[0], 'BEGIN CERTIFICATE') ? "read-ok\n" : "read-mismatch\n";
?>
--EXPECT--
sign-verify-ok
encrypt-decrypt-ok
read-ok
