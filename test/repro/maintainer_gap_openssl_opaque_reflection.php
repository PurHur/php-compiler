<?php
/**
 * Issue #28567 - openssl_pkey_new/get_public/get_private, x509_read, csr_new, verify Reflection returns.
 * php-src: ext/openssl/openssl.stub.php
 */
foreach ([
    'openssl_pkey_new',
    'openssl_pkey_get_public',
    'openssl_pkey_get_private',
    'openssl_x509_read',
    'openssl_csr_new',
    'openssl_verify',
] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', (string) ($r->getReturnType() ?? 'untyped'), PHP_EOL;
}
