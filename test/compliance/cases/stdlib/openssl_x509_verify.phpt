--TEST--
stdlib openssl_x509_verify() — self-signed fixture + wrong public key (#6595, ext/openssl/x509.c)
--SKIPIF--
<?php if (!PHPCompiler\ext\openssl\VmOpensslX509Native::available()) die('skip no libcrypto FFI'); ?>
--FILE--
<?php
declare(strict_types=1);
$pem = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIIBdTCCAR+gAwIBAgIUQ43V2DdE7emGxnFsE7m0goBT+NwwDQYJKoZIhvcNAQEL
BQAwDzENMAsGA1UEAwwEdGVzdDAeFw0yNjA2MTMwNTEyMTVaFw0yNzA2MTMwNTEy
MTVaMA8xDTALBgNVBAMMBHRlc3QwXDANBgkqhkiG9w0BAQEFAANLADBIAkEA0v3U
b1alT3eTGKYXeaOwTCnYlFHIqbPRN9QIA5uLBoMBzYkvyYrB0Cn4JJ9z8cHXC28b
JoiMF0c4ieUKGJDbLQIDAQABo1MwUTAdBgNVHQ4EFgQUYvFstHPOXFH9MML8oieH
aAO8cDgwHwYDVR0jBBgwFoAUYvFstHPOXFH9MML8oieHaAO8cDgwDwYDVR0TAQH/
BAUwAwEB/zANBgkqhkiG9w0BAQsFAANBABzgKedHOEb9sSDCE5EPqQKzRme8+xHH
lLUgzBEC/Lp5Cj7g7xQ2xE9t8iVtgsBwSaa6WjzJWC97N8UsdFNe0i0=
-----END CERTIFICATE-----
PEM;
$wrongPub = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MFwwDQYJKoZIhvcNAQEBBQADSwAwSAJBALP2oJDAziQ0u1HX7m9s6GGGXY145oxB
lQCkNI3DbI88fFIs2WXXs8qvQm/2tWfHqTxZtCj11jvw632jv6SXTdMCAwEAAQ==
-----END PUBLIC KEY-----
PEM;
var_export(function_exists('openssl_x509_verify'));
echo "\n";
$cert = openssl_x509_read($pem);
var_export(openssl_x509_verify($cert, $cert));
echo "\n";
var_export(openssl_x509_verify($pem, $pem));
echo "\n";
var_export(openssl_x509_verify($cert, $wrongPub));
echo "\n";
enum E:string { case A = 'x'; }
try {
    openssl_x509_verify($cert, E::A);
    echo "no-error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
var_export(openssl_x509_verify('not-a-cert', $wrongPub));
echo "\n";
--EXPECTF--
PHP Warning:  openssl_x509_verify(): X.509 Certificate cannot be retrieved in %s on line %d
true
1
1
0
openssl_x509_verify(): Argument #2 ($public_key) must be of type OpenSSLAsymmetricKey|OpenSSLCertificate|string, E given
false
