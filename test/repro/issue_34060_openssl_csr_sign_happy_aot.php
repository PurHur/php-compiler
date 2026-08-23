<?php
/**
 * Repro #34060 — openssl_csr_sign(CSR PEM, null, key PEM, days) AOT bake.
 *
 * @see php-src ext/openssl/openssl.c PHP_FUNCTION(openssl_csr_sign)
 */
$csr = <<<'PEM'
-----BEGIN CERTIFICATE REQUEST-----
MIIBEjCBvQIBADBYMQswCQYDVQQGEwJVUzERMA8GA1UEAwwIYW90LXNpZ24xEzAR
BgNVBAgMClNvbWUtU3RhdGUxITAfBgNVBAoMGEludGVybmV0IFdpZGdpdHMgUHR5
IEx0ZDBcMA0GCSqGSIb3DQEBAQUAA0sAMEgCQQDa+nND5GkRSS9y07V+2yFm7Oon
ie5SvoMPmEA6Ehq8xaDD8dv069gQxnArhnTHRr4mhyfZCg9pXmPG7o7MaRsJAgMB
AAGgADANBgkqhkiG9w0BAQsFAANBAE9j3TBRsZhSRl9AkAO9NKKqzQpITeOPNd9i
ip9ihP6NHZjd0uIXsMzDDelOsVANnHYk6sjSYSw4wBWNZd1MITQ=
-----END CERTIFICATE REQUEST-----
PEM;
$key = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIBVAIBADANBgkqhkiG9w0BAQEFAASCAT4wggE6AgEAAkEA2vpzQ+RpEUkvctO1
ftshZuzqJ4nuUr6DD5hAOhIavMWgw/Hb9OvYEMZwK4Z0x0a+Jocn2QoPaV5jxu6O
zGkbCQIDAQABAkEAzeWsN+wu9rfvy3JRN6RnddXSHbdNxbOonCM2UOPxDAiw+ENA
cotF2EFfr4PRw2tJTViM+xGwf4j92AYBd7Vc2QIhAPzaw4l6rh6M1wANO6SwvcZX
3Y68LnivrN4Uv9RczOVzAiEA3bPOB4rAb5P5cSDYVpnf+3PB5DVh4EkjJ6dZPOAy
vpMCID1vHYEimHl9uKMfk/Uwp/svz/nlCNlzvWl72xvKrFG3AiBLrK4swN3CuD2y
scVmeguMJw0NunL4Pb60MFkzgEuR5QIgLvbCISUlC3P9N/IIFHckbk7iEotTHV5Y
isElxqQnNxk=
-----END PRIVATE KEY-----
PEM;

$cert = openssl_csr_sign($csr, null, $key, 30);
echo is_object($cert) ? get_class($cert) : "fail", PHP_EOL;
$bad = openssl_csr_sign('not-a-csr', null, $key, 30);
echo false === $bad ? 'bad-false' : 'bad-other', PHP_EOL;
