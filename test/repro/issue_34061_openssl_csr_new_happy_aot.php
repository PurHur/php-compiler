<?php
/**
 * Repro #34061 — openssl_csr_new(DN + key PEM literal) AOT bake.
 *
 * DN/options must be inline array literals so compileTimeAssoc is preserved
 * (assigned temps drop the fold metadata — peer openssl_pkey_new options).
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_csr_new)
 */
$keyPem = '-----BEGIN PRIVATE KEY-----
MIIBVQIBADANBgkqhkiG9w0BAQEFAASCAT8wggE7AgEAAkEAxQo/SjyBeDWRFZ3e
Jzd4KZASqeBcQ+EEy8fkDo4wTjg5fMDATtM46L6hH79LdcbNEAwTdD0igy3uYus0
RRowfQIDAQABAkAq5bw5sUqOnTrk9eWzrAPhKJinm0z7CjY9F1uzP4mMvZabZZHj
KTnMX+Qnc0g9Tn7Pc5bWEjLN7+qDaYGahMGBAiEA+HhRFTTYVmz79D+76Cv0/9yc
aiaGByoDmatTV7pARUkCIQDLAu0GmAW0YQg1wBYNqEpMsxH2Ncww8sYiIc+bl0v1
lQIgINkHHx6VWxedV3T1ioQFJ64qn33oShorz6zun7JnvMECIQCBnligQShDR0Dq
sL5j8fOejScGwMqi5h9DY7seaLeDEQIhALKmJ9dvcQNt+85WW5uc/9POEDkRbU/8
l0A4jEMDGLHm
-----END PRIVATE KEY-----
';
$csr = openssl_csr_new(
    ['countryName' => 'US', 'commonName' => 'test'],
    $keyPem,
    ['digest_alg' => 'sha256']
);
echo is_object($csr) ? get_class($csr) : 'fail', PHP_EOL;
$badKey = 'not-a-key';
$bad = openssl_csr_new(['commonName' => 'x'], $badKey);
echo false === $bad ? 'bad-false' : 'bad-other', PHP_EOL;
