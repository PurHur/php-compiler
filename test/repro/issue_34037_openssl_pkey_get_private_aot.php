<?php
/**
 * Repro #34037 — openssl_pkey_get_private(PEM literal) AOT bake.
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_pkey_get_private)
 */
$pem = '-----BEGIN PRIVATE KEY-----
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
$k = openssl_pkey_get_private($pem);
echo is_object($k) ? get_class($k) : "fail", PHP_EOL;
$d = openssl_pkey_get_details($k);
echo is_array($d) ? (string)$d["bits"] : "no-details", PHP_EOL;
