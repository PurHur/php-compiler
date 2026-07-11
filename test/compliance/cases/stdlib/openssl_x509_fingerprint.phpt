--TEST--
stdlib openssl_x509_fingerprint() — sha1/sha256 digest of fixture PEM (#6524, ext/openssl/x509.c)
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
var_export(function_exists('openssl_x509_fingerprint'));
echo "\n";
var_export(openssl_x509_fingerprint($pem));
echo "\n";
var_export(openssl_x509_fingerprint($pem, 'sha256'));
echo "\n";
$cert = openssl_x509_read($pem);
var_export(openssl_x509_fingerprint($cert, 'sha256'));
echo "\n";
var_export(openssl_x509_fingerprint($pem, 'bogus'));
echo "\n";
var_export(openssl_x509_fingerprint('not-a-cert'));
echo "\n";
--EXPECTF--
PHP Warning:  openssl_x509_fingerprint(): Unknown digest algorithm in %s on line %d
PHP Warning:  openssl_x509_fingerprint(): X.509 Certificate cannot be retrieved in %s on line %d
true
'64ad7d6f5f0c223a924466fd2fd18aaa38abc8bd'
'52afbdf3734420dc21025b8418bf6281f9740069d08dfaa0d700510b41679e31'
'52afbdf3734420dc21025b8418bf6281f9740069d08dfaa0d700510b41679e31'
false
false
