<?php
/**
 * Repro #34054 — openssl_csr_get_public_key(CSR PEM literal) AOT bake.
 *
 * @see php-src ext/openssl/xp.c PHP_FUNCTION(openssl_csr_get_public_key)
 */
$pem = <<<'PEM'
-----BEGIN CERTIFICATE REQUEST-----
MIHWMIGBAgEAMBwxCzAJBgNVBAYTAlVTMQ0wCwYDVQQDDAR0ZXN0MFwwDQYJKoZI
hvcNAQEBBQADSwAwSAJBAJvvRzuZyqMSXp+b1ue/y+w5CCj/LLvsH5hBh0lxVOL1
iKowgGV0P/wH22UnOIvpJ7NvIXMu7JexpaqdHln2UEkCAwEAAaAAMA0GCSqGSIb3
DQEBCwUAA0EAXPcbsOj7qZsACzUrR7B0sWkNxUtANNvdwF9UIBu8n+Mkz5mWvmN/
xHd8PFTBAcitzxQQwDAI1Vj3EUW7Qn3lIw==
-----END CERTIFICATE REQUEST-----
PEM;

$k = openssl_csr_get_public_key($pem);
echo is_object($k) ? get_class($k) : 'fail', PHP_EOL;
$d = openssl_pkey_get_details($k);
echo is_array($d) ? (string) $d['bits'] : 'no-details', PHP_EOL;
$bad = openssl_csr_get_public_key('not-a-csr');
echo false === $bad ? 'bad-false' : 'bad-other', PHP_EOL;
