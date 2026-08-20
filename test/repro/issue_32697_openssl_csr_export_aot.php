<?php

declare(strict_types=1);

$pem = <<<'PEM'
-----BEGIN CERTIFICATE REQUEST-----
MIHWMIGBAgEAMBwxCzAJBgNVBAYTAlVTMQ0wCwYDVQQDDAR0ZXN0MFwwDQYJKoZI
hvcNAQEBBQADSwAwSAJBAJvvRzuZyqMSXp+b1ue/y+w5CCj/LLvsH5hBh0lxVOL1
iKowgGV0P/wH22UnOIvpJ7NvIXMu7JexpaqdHln2UEkCAwEAAaAAMA0GCSqGSIb3
DQEBCwUAA0EAXPcbsOj7qZsACzUrR7B0sWkNxUtANNvdwF9UIBu8n+Mkz5mWvmN/
xHd8PFTBAcitzxQQwDAI1Vj3EUW7Qn3lIw==
-----END CERTIFICATE REQUEST-----
PEM;

$out = '';
var_export(openssl_csr_export($pem, $out));
echo '|';
echo (str_contains($out, 'BEGIN CERTIFICATE REQUEST') && str_contains($out, 'END CERTIFICATE REQUEST'))
    ? 'pem-ok'
    : 'pem-bad';
echo '|';
$bad = '';
var_export(openssl_csr_export('not-a-csr', $bad));
echo "\n";
