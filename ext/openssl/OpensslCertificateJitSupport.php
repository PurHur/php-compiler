<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * OpenSSLCertificate property names for JIT/AOT openssl_x509_read (#34048).
 *
 * php-src: ext/openssl/xp.c — PHP_FUNCTION(openssl_x509_read)
 */
final class OpensslCertificateJitSupport
{
    public const CLASS_NAME = 'OpenSSLCertificate';

    /** PEM certificate material mirrored for thin AOT (peer OpenSSLAsymmetricKey __osslPem). */
    public const PROP_PEM = '__osslPem';
}
