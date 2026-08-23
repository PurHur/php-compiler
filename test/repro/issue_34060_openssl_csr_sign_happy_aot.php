<?php
/**
 * Repro #34060 — openssl_csr_sign(CSR PEM, null, key PEM, days) AOT bake.
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_csr_sign)
 */
$csr = <<<'PEM'
-----BEGIN CERTIFICATE REQUEST-----
MIHWMIGBAgEAMBwxCzAJBgNVBAYTAlVTMQ0wCwYDVQQDDAR0ZXN0MFwwDQYJKoZI
hvcNAQEBBQADSwAwSAJBAMQGKrhKW8Uq/Jczw6uLKpurB52cXPCjtep+rztTtIqB
92nvkbrZhaKS8Jc8qpaO6NcjSva8cQcFY3MBgeW3mv8CAwEAAaAAMA0GCSqGSIb3
DQEBCwUAA0EAVvUNEt80bJfSALHcsinR/yRtXol9ETp5UtKMpIjP7rT6iMa8oOJW
06cVXrw/x/7wwziqnWz3wQuklXM5rUHV/Q==
-----END CERTIFICATE REQUEST-----
PEM;

$key = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIBVgIBADANBgkqhkiG9w0BAQEFAASCAUAwggE8AgEAAkEAxAYquEpbxSr8lzPD
q4sqm6sHnZxc8KO16n6vO1O0ioH3ae+RutmFopLwlzyqlo7o1yNK9rxxBwVjcwGB
5bea/wIDAQABAkEAuJnuJTuxjM7crTAMd0JJz+uS8nTMebpSmRDQyRgdD8mJ/7rI
+jwi1OHmPIx7S58BC/AJhY33dDOHN5GRAGEnQQIhAPLVB7tVDok5tTx7X9Y216IW
OtQxXoVT1g85n09/ZbMPAiEAzqdaKs+5wm0Tx3pIS0KyK/RHiTj2b0ukFiJEq3Xh
2RECIQDdIlRdKzMGki/SOUPoDp9Fstq125OI9PStfrruKUTSzwIgTSzsnI5lJjoM
J/P/6bNnzMh2qsWOKvRJvEZh9NKaXLECIQDT2i04xL7fxtE28nCph4d7+7d1L42s
X+ttSPVIysH3sg==
-----END PRIVATE KEY-----
PEM;

$cert = openssl_csr_sign($csr, null, $key, 30);
echo is_object($cert) ? get_class($cert) : 'fail', PHP_EOL;
$bad = openssl_csr_sign('not-a-csr', null, $key, 30);
echo false === $bad ? 'bad-false' : 'bad-other', PHP_EOL;
