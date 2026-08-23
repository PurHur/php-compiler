<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * OpenSSLCertificateSigningRequest property names for JIT/AOT openssl_csr_new (#34061)
 * and openssl_csr_sign consumers (#34060).
 *
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_csr_new)
 */
final class OpensslCsrJitSupport
{
    public const CLASS_NAME = 'OpenSSLCertificateSigningRequest';

    /** CSR PEM material mirrored for thin AOT (peer OpenSSLCertificate / OpenSSLAsymmetricKey __osslPem). */
    public const PROP_PEM = '__osslPem';
}
