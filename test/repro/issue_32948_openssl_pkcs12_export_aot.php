<?php

declare(strict_types=1);

// Fixed RSA-512 self-signed cert + matching key (PKCS#12 MAC salt is non-deterministic).
$cert = <<<'CERT'
-----BEGIN CERTIFICATE-----
MIIBgzCCAS2gAwIBAgIUN4BOXQWMs9kdEb2odDar8wu5bGIwDQYJKoZIhvcNAQEL
BQAwFjEUMBIGA1UEAwwLcGhwYy1wa2NzMTIwHhcNMjYwODIwMTgyNzEzWhcNMzYw
ODE3MTgyNzEzWjAWMRQwEgYDVQQDDAtwaHBjLXBrY3MxMjBcMA0GCSqGSIb3DQEB
AQUAA0sAMEgCQQDPen/ndMOFo6W0vb4s3R4vPmbDQuM6cOtZMs1TczJ/c6RgRW4g
e+CJFAFUmYiFj4fZ51pHhExJpH3DsrsxS8mDAgMBAAGjUzBRMB0GA1UdDgQWBBSY
adGGhJ6Q5G8c5NzICCdN2mpiUzAfBgNVHSMEGDAWgBSYadGGhJ6Q5G8c5NzICCdN
2mpiUzAPBgNVHRMBAf8EBTADAQH/MA0GCSqGSIb3DQEBCwUAA0EAdK4kpJgNJWgs
8oneX7ayjHRFqOexaasHUZRY7/DpCWDF6w7DMvgOU/EPnJEUx1ChQVFNrlVOazyb
FgIYVGOfEg==
-----END CERTIFICATE-----
CERT;
$key = <<<'KEY'
-----BEGIN PRIVATE KEY-----
MIIBUwIBADANBgkqhkiG9w0BAQEFAASCAT0wggE5AgEAAkEAz3p/53TDhaOltL2+
LN0eLz5mw0LjOnDrWTLNU3Myf3OkYEVuIHvgiRQBVJmIhY+H2edaR4RMSaR9w7K7
MUvJgwIDAQABAkBbVX/MsjgIMnwVzplTQpuxDHVMa7t/1ImmIJkGrWWDeOfgSTab
omB6Kl4/h7yG24JCLGkXTFCvJN9eH/YsP8iBAiEA7FO2HLpXwkhGkqR873rAIDcm
H6HCPQRuuSNa3T9S/iMCIQDgwAHrgZG0Z8j5Zi2QPAWu0liijv5z5oQiVtdbyX3N
IQIgCBs2+/VIXVmtUgpiXrSPMouxuxQJXZ5xTdhwnXY2mpECICwlavsge0dNb4uV
h3OiZpddV+2uWsrXR7MbDbhIzr4hAiBd58I8C3BqRDZPn3cn6uHR/bO+7bp7MxcZ
D4xY3UPAFg==
-----END PRIVATE KEY-----
KEY;

$out = '';
var_export(openssl_pkcs12_export($cert, $out, $key, 'secret'));
echo '|';
// Avoid strlen($out): standalone AOT can keep the '' compile-time literal (#15642).
echo md5($out) === 'd41d8cd98f00b204e9800998ecf8427e' ? 'blob-bad' : 'blob-ok';
echo '|';
$bad = '';
var_export(openssl_pkcs12_export('not-a-cert', $bad, $key, 'secret'));
echo "\n";
