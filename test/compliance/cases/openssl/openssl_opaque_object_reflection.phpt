--TEST--
openssl opaque object Reflection returns (VM, issue #28567)
--FILE--
<?php
foreach ([
    'openssl_pkey_new',
    'openssl_pkey_get_public',
    'openssl_pkey_get_private',
    'openssl_x509_read',
    'openssl_csr_new',
    'openssl_verify',
] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', (string) ($r->getReturnType() ?? 'untyped'), "\n";
}
?>
--EXPECT--
openssl_pkey_new ret=OpenSSLAsymmetricKey|false
openssl_pkey_get_public ret=OpenSSLAsymmetricKey|false
openssl_pkey_get_private ret=OpenSSLAsymmetricKey|false
openssl_x509_read ret=OpenSSLCertificate|false
openssl_csr_new ret=OpenSSLCertificateSigningRequest|bool
openssl_verify ret=int|false
