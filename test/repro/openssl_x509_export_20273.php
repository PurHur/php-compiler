<?php
// Repro #20273 — openssl_x509_export / export_to_file round-trip
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

if (!function_exists('openssl_x509_export') || !function_exists('openssl_x509_export_to_file')) {
    fwrite(STDERR, "fail: functions missing\n");
    exit(1);
}

$out = '';
if (!openssl_x509_export($pem, $out) || !str_contains($out, 'BEGIN CERTIFICATE')) {
    fwrite(STDERR, "fail: export pem\n");
    exit(1);
}

$file = sys_get_temp_dir().'/phpc-x509-export-repro-'.getmypid().'.pem';
if (!openssl_x509_export_to_file($pem, $file) || !is_file($file)) {
    fwrite(STDERR, "fail: export_to_file\n");
    exit(1);
}
@unlink($file);
echo "ok\n";
