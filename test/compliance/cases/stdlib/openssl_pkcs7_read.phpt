--TEST--
openssl_pkcs7_read() extracts cert PEMs from PKCS#7 PEM content (#20305, ext/openssl/openssl.c)
--FILE--
<?php
if (!function_exists('openssl_pkcs7_read')) {
    echo "missing:openssl_pkcs7_read\n";
    exit(1);
}
$p7b = __DIR__ . '/test/fixtures/openssl/cert.p7b';
if (!is_file($p7b)) {
    $p7b = dirname(__DIR__, 3) . '/fixtures/openssl/cert.p7b';
}
if (!is_file($p7b)) {
    echo "fixture-missing\n";
    exit(1);
}
$data = file_get_contents($p7b);
$certPem = __DIR__ . '/test/fixtures/openssl/pkcs7_test_cert.pem';
if (!is_file($certPem)) {
    $certPem = dirname(__DIR__, 3) . '/fixtures/openssl/pkcs7_test_cert.pem';
}

$empty = [];
var_export(openssl_pkcs7_read('', $empty));
echo "\n";

$notPkcs7 = [];
var_export(openssl_pkcs7_read(is_file($certPem) ? file_get_contents($certPem) : "-----BEGIN CERTIFICATE-----\nMII=\n-----END CERTIFICATE-----\n", $notPkcs7));
echo "\n";

$certs = [];
$ok = openssl_pkcs7_read($data, $certs);
var_export($ok);
echo "\n";
echo is_array($certs) ? 'array' : gettype($certs);
echo ':', count($certs), "\n";
$pem = $certs[0] ?? '';
echo (is_string($pem) && str_starts_with($pem, '-----BEGIN CERTIFICATE-----') && str_contains($pem, '-----END CERTIFICATE-----')) ? "pem-ok\n" : "pem-bad\n";
echo strlen($pem) > 500 ? "pem-len-ok\n" : "pem-len-bad\n";
?>
--EXPECT--
false
false
true
array:1
pem-ok
pem-len-ok
