--TEST--
AOT: openssl_x509_export() PEM / invalid / X509_print text (#32557 leftover of #20273, ext/openssl/openssl.c)
--SKIPIF--
<?php
require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
require_once dirname(__DIR__, 4) . '/ext/openssl/VmOpensslX509Native.php';
if (!\PHPCompiler\ext\openssl\VmOpensslX509Native::available()) {
    echo 'skip';
}
?>
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

$out = '';
var_export(openssl_x509_export($pem, $out));
echo '|';
echo (str_contains($out, 'BEGIN CERTIFICATE') && str_contains($out, 'END CERTIFICATE')) ? 'pem-ok' : 'pem-bad';
echo '|';
$bad = '';
var_export(openssl_x509_export('not-a-cert', $bad));
echo '|';
$text = '';
var_export(openssl_x509_export($pem, $text, false));
echo '|';
echo (str_contains($text, 'Certificate:') && str_contains($text, 'BEGIN CERTIFICATE')) ? 'text-ok' : 'text-bad';
echo "\n";
--EXPECT--
true|pem-ok|false|true|text-ok
