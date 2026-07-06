--TEST--
stdlib openssl_x509_parse() — subject/issuer from PEM (#6274, ext/openssl/xp.c)
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
var_export(is_array(openssl_x509_parse($pem)));
echo "\n";
$info = openssl_x509_parse($pem);
var_export($info['subject']['CN'] ?? null);
echo "\n";
var_export($info['issuer']['CN'] ?? null);
echo "\n";
var_export(openssl_x509_parse('not-a-cert'));
echo "\n";
--EXPECT--
true
'test'
'test'
false
