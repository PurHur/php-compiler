--TEST--
openssl_pkcs7_sign/verify/encrypt/decrypt S/MIME round-trip (#6804, ext/openssl/openssl.c)
--FILE--
<?php
foreach (['openssl_pkcs7_sign', 'openssl_pkcs7_verify', 'openssl_pkcs7_encrypt', 'openssl_pkcs7_decrypt'] as $fn) {
    if (!function_exists($fn)) {
        echo "missing:{$fn}\n";
        exit(1);
    }
}
foreach (['PKCS7_DETACHED', 'PKCS7_TEXT', 'PKCS7_NOINTERN', 'PKCS7_NOVERIFY', 'PKCS7_BINARY', 'OPENSSL_CIPHER_AES_128_CBC'] as $c) {
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
$tmpdir = sys_get_temp_dir() . '/phpc_pkcs7_' . getmypid();
@mkdir($tmpdir);
$msg = $tmpdir . '/msg.txt';
$signed = $tmpdir . '/signed.p7m';
$verified = $tmpdir . '/verified.txt';
$enc = $tmpdir . '/enc.p7m';
$dec = $tmpdir . '/dec.txt';
file_put_contents($msg, "hello pkcs7\n");

$flagsBinary = PKCS7_BINARY;
$flagsNoVerify = PKCS7_NOVERIFY;
$cipher = OPENSSL_CIPHER_AES_128_CBC;

if (!openssl_pkcs7_sign($msg, $signed, $cert, $key, [], $flagsBinary)) {
    echo "sign-fail\n";
    exit(1);
}
$vr = openssl_pkcs7_verify($signed, $flagsNoVerify, null, [], null, $verified);
if (true !== $vr) {
    echo "verify-fail:" . var_export($vr, true) . "\n";
    exit(1);
}
echo file_get_contents($verified) === "hello pkcs7\n" ? "sign-verify-ok\n" : "sign-verify-mismatch\n";

if (!openssl_pkcs7_encrypt($msg, $enc, $cert, [], $flagsBinary, $cipher)) {
    echo "encrypt-fail\n";
    exit(1);
}
if (!openssl_pkcs7_decrypt($enc, $dec, $cert, $key)) {
    echo "decrypt-fail\n";
    exit(1);
}
echo file_get_contents($dec) === "hello pkcs7\n" ? "encrypt-decrypt-ok\n" : "encrypt-decrypt-mismatch\n";
?>
--EXPECT--
sign-verify-ok
encrypt-decrypt-ok
