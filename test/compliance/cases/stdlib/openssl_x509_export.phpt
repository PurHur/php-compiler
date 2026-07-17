--TEST--
stdlib openssl_x509_export()/export_to_file() — PEM round-trip (#20273, ext/openssl/openssl.c)
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
var_export(function_exists('openssl_x509_export'));
echo "\n";
var_export(function_exists('openssl_x509_export_to_file'));
echo "\n";

$bad = '';
var_export(openssl_x509_export('not-a-cert', $bad));
echo "\n";

$out = '';
var_export(openssl_x509_export($pem, $out));
echo "\n";
echo (str_contains($out, 'BEGIN CERTIFICATE') && str_contains($out, 'END CERTIFICATE')) ? "pem-ok\n" : "pem-bad\n";

$cert = openssl_x509_read($pem);
$out2 = '';
var_export(openssl_x509_export($cert, $out2));
echo "\n";
echo ($out === $out2) ? "object-match\n" : "object-mismatch\n";

$withText = '';
var_export(openssl_x509_export($pem, $withText, false));
echo "\n";
echo (str_contains($withText, 'Certificate:') && str_contains($withText, 'BEGIN CERTIFICATE')) ? "text-ok\n" : "text-bad\n";

$dir = sys_get_temp_dir().'/phpc-x509-export-'.getmypid();
mkdir($dir);
$file = $dir.'/cert.pem';
var_export(openssl_x509_export_to_file($pem, $file));
echo "\n";
$fromFile = file_get_contents($file);
echo ($fromFile === $out) ? "file-ok\n" : "file-bad\n";
unlink($file);
rmdir($dir);
--EXPECTF--
PHP Warning:  openssl_x509_export(): X.509 Certificate cannot be retrieved in %s on line %d
true
true
false
true
pem-ok
true
object-match
true
text-ok
true
file-ok
