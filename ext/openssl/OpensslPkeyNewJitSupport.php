<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

/**
 * OpenSSLAsymmetricKey property names for JIT/AOT openssl_pkey_new (#34015).
 *
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_pkey_new)
 */
final class OpensslPkeyNewJitSupport
{
    public const CLASS_NAME = 'OpenSSLAsymmetricKey';

    /** PEM private key material mirrored for thin AOT (peer HashContext __hcAlgo). */
    public const PROP_PEM = '__osslPem';
}
