--TEST--
openssl_cms_verify nullable $certificates + ca_info array; Reflection arity (#22368, re-#6592)
--FILE--
<?php
$rf = new ReflectionFunction('openssl_cms_verify');
echo 'params=', $rf->getNumberOfParameters(), "\n";
$certs = $rf->getParameters()[2] ?? null;
echo 'certificates_nullable=', $certs && $certs->allowsNull() ? '1' : '0', "\n";
echo 'certificates_name=', $certs ? $certs->getName() : 'missing', "\n";

$certPath = __DIR__ . '/test/fixtures/openssl/pkcs7_test_cert.pem';
$keyPath = __DIR__ . '/test/fixtures/openssl/pkcs7_test_key.pem';
if (!is_file($certPath)) {
    $certPath = dirname(__DIR__, 3) . '/fixtures/openssl/pkcs7_test_cert.pem';
    $keyPath = dirname(__DIR__, 3) . '/fixtures/openssl/pkcs7_test_key.pem';
}
$cert = file_get_contents($certPath);
$key = file_get_contents($keyPath);
$tmpdir = sys_get_temp_dir() . '/phpc_cms_null_' . getmypid();
@mkdir($tmpdir);
$msg = $tmpdir . '/msg.txt';
$signed = $tmpdir . '/signed.cms';
file_put_contents($msg, "payload\n");
if (!openssl_cms_sign($msg, $signed, $cert, $key, [], OPENSSL_CMS_BINARY)) {
    echo "sign-fail\n";
    exit(1);
}
// Inline OPENSSL_* ConstFetch + null + array — ARG_SEND wiring (#22368).
try {
    $vr = openssl_cms_verify($signed, OPENSSL_CMS_NOVERIFY, null, [$certPath]);
    echo 'verify_null_ca=', true === $vr || false === $vr ? 'bool' : gettype($vr), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
    exit(1);
}
?>
--EXPECT--
params=9
certificates_nullable=1
certificates_name=certificates
verify_null_ca=bool
