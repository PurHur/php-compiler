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

$file = '/tmp/phpc_x509_export_to_file_32557.pem';
var_export(openssl_x509_export_to_file($pem, $file));
echo '|';
$written = @file_get_contents($file);
echo (\is_string($written) && str_contains($written, 'BEGIN CERTIFICATE') && str_contains($written, 'END CERTIFICATE'))
    ? 'file-ok'
    : 'file-bad';
echo '|';
var_export(openssl_x509_export_to_file('not-a-cert', $file));
@unlink($file);
echo "\n";
